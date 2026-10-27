<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Sale;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;

class SaleTest extends CrmTestCase
{
    private function payload(Customer $customer, array $changes = []): array
    {
        return array_replace(['customer_id' => $customer->id, 'lead_id' => null, 'amount' => '1200.50', 'currency' => 'USD', 'closed_at' => '2026-10-20T12:00:00Z', 'request_id' => (string) Str::uuid()], $changes);
    }

    public function test_anonymous_cannot_read_or_record_sales(): void
    {
        $this->getJson('/api/v1/sales')->assertUnauthorized();
        $this->postJson('/api/v1/sales', [])->assertUnauthorized();
    }

    #[TestWith(['Admin'])]
    #[TestWith(['Manager'])]
    public function test_recording_sale_is_exact_audited_and_idempotent(string $role): void
    {
        $actor = $this->signIn($role);
        $this->travelTo('2026-10-26 12:00:00');
        $customer = Customer::factory()->create();
        $payload = $this->payload($customer);
        $sale = $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.amount', '1200.50')->assertJsonMissingPath('data.request_id')->json('data.id');
        $this->postJson('/api/v1/sales', $payload)->assertOk()->assertJsonPath('data.id', $sale);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('customer_activities', 1);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $actor->id, 'action' => 'sales.record:'.$sale]);
        $this->postJson('/api/v1/sales', [...$payload, 'amount' => '999.99'])->assertConflict();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_lead_must_be_won_and_linked_to_selected_customer(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 12:00:00');
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->for($customer)->create();
        $this->postJson('/api/v1/sales', $this->payload($customer, ['lead_id' => $lead->id]))->assertUnprocessable()->assertJsonValidationErrors('lead_id');
        $lead->forceFill(['pipeline_stage_id' => PipelineStage::where('slug', 'won')->value('id')])->save();
        $this->postJson('/api/v1/sales', $this->payload(Customer::factory()->create(), ['lead_id' => $lead->id]))->assertUnprocessable();
        $this->postJson('/api/v1/sales', $this->payload($customer, ['lead_id' => $lead->id]))->assertCreated();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_void_preserves_entry_and_removes_revenue_without_touching_lead_stage(): void
    {
        $actor = $this->signIn();
        $this->travelTo('2026-10-26 12:00:00');
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->for($customer)->create(['pipeline_stage_id' => PipelineStage::where('slug', 'won')->value('id')]);
        $sale = Sale::factory()->for($customer)->for($lead)->create(['closed_at' => '2026-10-20']);
        $this->patchJson('/api/v1/sales/'.$sale->id.'/void', ['version' => 1, 'reason' => 'Incorrect amount'])->assertOk()->assertJsonPath('data.status', 'voided')->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'voided_by' => $actor->id, 'void_reason' => 'Incorrect amount']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.void:'.$sale->id]);
        $this->assertSame('won', $lead->fresh()->stage->slug);
        $this->getJson('/api/v1/dashboard/summary?preset=this_month')->assertOk()->assertJsonPath('data.metrics.total_recorded_sales', '0.00');
        $this->patchJson('/api/v1/sales/'.$sale->id.'/void', ['version' => 2, 'reason' => 'Again'])->assertConflict();
    }

    public function test_stale_void_and_missing_reason_do_not_modify_sale(): void
    {
        $this->signIn();
        $sale = Sale::factory()->create(['version' => 2]);
        $this->patchJson('/api/v1/sales/'.$sale->id.'/void', ['version' => 1, 'reason' => 'Correction'])->assertConflict();
        $this->patchJson('/api/v1/sales/'.$sale->id.'/void', ['version' => 2])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_staff_can_only_read_sales_for_own_leads_and_cannot_record_or_void(): void
    {
        $staff = $this->signIn('Staff');
        $customer = Customer::factory()->create();
        $own = Lead::factory()->for($customer)->for($staff, 'assignee')->create();
        $sale = Sale::factory()->for($customer)->for($own)->create();
        Sale::factory()->for($customer)->create();
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $sale->id)->assertJsonMissingPath('data.0.recorder.email');
        $this->postJson('/api/v1/sales', $this->payload($customer))->assertForbidden();
        $this->patchJson('/api/v1/sales/'.$sale->id.'/void', ['version' => 1, 'reason' => 'No'])->assertForbidden();
    }

    public function test_sales_access_is_revoked_when_assigned_lead_is_archived(): void
    {
        $staff = $this->signIn('Staff');
        $customer = Customer::factory()->create();
        $lead = Lead::factory()->for($customer)->for($staff, 'assignee')->create();
        Sale::factory()->for($customer)->for($lead)->create();
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 1);
        $lead->delete();
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_amount_currency_future_date_and_actor_injection_are_rejected(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 12:00:00');
        $customer = Customer::factory()->create();
        $this->postJson('/api/v1/sales', $this->payload($customer, ['amount' => '0', 'currency' => 'XXX', 'closed_at' => '2027-01-01T00:00:00Z', 'recorded_by' => 1]))->assertUnprocessable()->assertJsonValidationErrors(['amount', 'currency', 'closed_at', 'recorded_by']);
        $this->postJson('/api/v1/sales', $this->payload($customer, ['amount' => '0.001', 'request_id' => 'not-a-uuid']))->assertUnprocessable()->assertJsonValidationErrors(['amount', 'request_id']);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_ledger_filters_sorting_and_pagination_never_mix_currency_totals(): void
    {
        $this->signIn();
        Sale::factory()->create(['currency' => 'EUR', 'amount' => '10.00']);
        Sale::factory()->create(['currency' => 'USD', 'amount' => '20.00']);
        Sale::factory()->create(['currency' => 'USD', 'amount' => '30.00', 'status' => 'voided']);
        $this->getJson('/api/v1/sales?currency=USD&status=completed&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', '20.00');
        $this->getJson('/api/v1/sales?sort=amount%3BDROP')->assertUnprocessable();
    }

    #[TestWith(['Admin', true])]
    #[TestWith(['Manager', true])]
    #[TestWith(['Staff', false])]
    public function test_sales_policy_matrix(string $role, bool $manages): void
    {
        $user = $this->signIn($role);
        $sale = Sale::factory()->create();
        $this->assertSame($manages, Gate::forUser($user)->allows('create', Sale::class));
        $this->assertSame($manages, Gate::forUser($user)->allows('void', $sale));
    }
}
