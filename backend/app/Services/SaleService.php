<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function record(User $actor, array $data): Sale
    {
        Gate::forUser($actor)->authorize('create', Sale::class);
        $candidate = new Sale([...$data, 'customer_id' => (int) $data['customer_id'], 'lead_id' => isset($data['lead_id']) ? (int) $data['lead_id'] : null]);
        try {
            return DB::transaction(function () use ($actor, $candidate, $data): Sale {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $existing = Sale::where('request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_if($existing->recorded_by !== $actor->id || $this->fingerprint($existing) !== $this->fingerprint($candidate), 409, 'This submission identifier was already used for different sale data.');

                    return $existing;
                }
                $customer = Customer::lockForUpdate()->findOrFail($candidate->customer_id);
                if ($candidate->lead_id) {
                    $lead = Lead::with('stage')->lockForUpdate()->findOrFail($candidate->lead_id);
                    if ($lead->customer_id !== $customer->id || $lead->stage->slug !== 'won') {
                        throw ValidationException::withMessages(['lead_id' => ['Choose a won lead linked to this customer.']]);
                    }
                }
                $candidate->recorded_by = $actor->id;
                $candidate->request_id = $data['request_id'];
                $candidate->save();
                $customer->activities()->create(['user_id' => $actor->id, 'activity_type' => 'sale_recorded', 'description' => 'Sale #'.$candidate->id.' recorded.']);
                DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'sales.record:'.$candidate->id, 'created_at' => now()]);

                return $candidate;
            }, 5);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This submission identifier is already in use.');
        }
    }

    public function void(User $actor, int $id, int $version, string $reason): Sale
    {
        return DB::transaction(function () use ($actor, $id, $version, $reason): Sale {
            $sale = Sale::visibleTo($actor)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('void', $sale);
            abort_if($sale->version !== $version, 409, 'This sale changed. Reload the ledger before retrying.');
            abort_if($sale->status === 'voided', 409, 'This sale is already voided.');
            $sale->status = 'voided';
            $sale->voided_at = now();
            $sale->voided_by = $actor->id;
            $sale->void_reason = $reason;
            $sale->version++;
            $sale->save();
            DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'sales.void:'.$sale->id, 'created_at' => now()]);

            return $sale;
        }, 5);
    }

    private function fingerprint(Sale $sale): array
    {
        return [(int) $sale->customer_id, $sale->lead_id === null ? null : (int) $sale->lead_id, $sale->amount, $sale->currency, $sale->closed_at->utc()->format('Y-m-d H:i:s')];
    }
}
