<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LeadService
{
    public function create(User $actor, array $data): Lead
    {
        return DB::transaction(function () use ($actor, $data): Lead {
            Gate::forUser($actor)->authorize('create', Lead::class);
            $lead = new Lead($data);
            $lead->created_by = $actor->id;
            $lead->pipeline_stage_id = PipelineStage::where('slug', 'new')->firstOrFail()->id;
            $lead->save();
            $this->record($lead, $actor, 'created', 'Lead created.');

            return $lead;
        }, 5);
    }

    public function update(User $actor, int $id, array $data): Lead
    {
        return DB::transaction(function () use ($actor, $id, $data): Lead {
            $lead = $this->locked($actor, $id);
            Gate::forUser($actor)->authorize('update', $lead);
            $this->version($lead, $data['version']);
            abort_if($lead->converted_at && array_key_exists('customer_id', $data) && ($data['customer_id'] === null ? null : (int) $data['customer_id']) !== $lead->customer_id, 409, 'A converted lead cannot be detached from its customer.');
            $oldAssignee = $lead->assigned_to;
            $lead->fill($data);
            $lead->version++;
            $lead->save();
            $this->record($lead, $actor, 'updated', 'Lead details updated.');
            if ($oldAssignee !== $lead->assigned_to) {
                $this->record($lead, $actor, 'assigned', 'Lead assignment changed.');
            }

            return $lead;
        }, 5);
    }

    public function changeStage(User $actor, int $id, int $stageId, int $version): Lead
    {
        return DB::transaction(function () use ($actor, $id, $stageId, $version): Lead {
            $lead = $this->locked($actor, $id);
            Gate::forUser($actor)->authorize('update', $lead);
            $this->version($lead, $version);
            if ($lead->pipeline_stage_id !== $stageId) {
                $previous = $lead->stage->name;
                $stage = PipelineStage::findOrFail($stageId);
                $lead->pipeline_stage_id = $stage->id;
                $lead->version++;
                $lead->save();
                $this->record($lead, $actor, 'stage_changed', 'Stage changed from '.$previous.' to '.$stage->name.'.');
            }

            return $lead;
        }, 5);
    }

    public function convert(User $actor, int $id, array $data): Lead
    {
        return DB::transaction(function () use ($actor, $id, $data): Lead {
            $lead = $this->locked($actor, $id);
            Gate::forUser($actor)->authorize('convert', $lead);
            $this->version($lead, $data['version']);
            abort_if($lead->customer_id !== null || $lead->converted_at !== null, 409, 'This lead is already linked to a customer.');
            $customer = new Customer($data);
            $customer->created_by = $actor->id;
            $customer->save();
            $customer->assignees()->sync(array_unique([...$data['assigned_user_ids'], $lead->assigned_to]));
            $customer->activities()->create(['user_id' => $actor->id, 'activity_type' => 'converted', 'description' => 'Customer created from lead #'.$lead->id.'.']);
            $lead->customer_id = $customer->id;
            $lead->converted_at = now();
            $lead->version++;
            $lead->save();
            $this->record($lead, $actor, 'converted', 'Lead converted to customer '.$customer->customer_code.'.');

            return $lead;
        }, 5);
    }

    public function delete(User $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version): void {
            $lead = $this->locked($actor, $id);
            Gate::forUser($actor)->authorize('delete', $lead);
            $this->version($lead, $version);
            $this->record($lead, $actor, 'deleted', 'Lead archived.');
            $lead->delete();
            DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'leads.delete:'.$id, 'created_at' => now()]);
        }, 5);
    }

    public function addActivity(User $actor, int $id, string $body): void
    {
        DB::transaction(function () use ($actor, $id, $body): void {
            $lead = $this->locked($actor, $id);
            Gate::forUser($actor)->authorize('update', $lead);
            $this->record($lead, $actor, 'note', $body);
        }, 5);
    }

    private function locked(User $actor, int $id): Lead
    {
        return Lead::visibleTo($actor)->lockForUpdate()->findOrFail($id);
    }

    private function version(Lead $lead, int $version): void
    {
        abort_if($lead->version !== $version, 409, 'This lead changed. Reload it before saving again.');
    }

    private function record(Lead $lead, User $actor, string $type, string $description): void
    {
        $lead->activities()->create(['user_id' => $actor->id, 'activity_type' => $type, 'description' => $description]);
    }
}
