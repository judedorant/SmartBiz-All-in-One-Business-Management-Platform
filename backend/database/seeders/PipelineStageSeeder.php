<?php

namespace Database\Seeders;

use App\Models\PipelineStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PipelineStageSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ([['New', 'new', 0], ['Contacted', 'contacted', 10], ['Qualified', 'qualified', 30], ['Proposal', 'proposal', 50], ['Negotiation', 'negotiation', 75], ['Won', 'won', 100], ['Lost', 'lost', 0]] as $position => $stage) {
                PipelineStage::updateOrCreate(['slug' => $stage[1]], ['name' => $stage[0], 'position' => $position, 'probability' => $stage[2]]);
            }
        });
    }
}
