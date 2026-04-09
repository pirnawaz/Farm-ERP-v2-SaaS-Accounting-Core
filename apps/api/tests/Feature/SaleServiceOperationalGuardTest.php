<?php

namespace Tests\Feature;

use App\Exceptions\CropCycleClosedException;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\SaleService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ensures line-item-free sale posting applies the same crop cycle / project guards as COGS posting.
 */
class SaleServiceOperationalGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_only_sale_is_blocked_when_crop_cycle_is_closed(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'CLOSED',
        ]);
        $hari = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $buyer = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['CUSTOMER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hari->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $buyer->id,
            'project_id' => $project->id,
            'amount' => 100.00,
            'posting_date' => '2024-06-15',
            'sale_date' => '2024-06-15',
            'due_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $this->expectException(CropCycleClosedException::class);

        app(SaleService::class)->postSale($sale->id, $tenant->id, '2024-06-15', 'guard-line-only-sale', 'tenant_admin');
    }
}
