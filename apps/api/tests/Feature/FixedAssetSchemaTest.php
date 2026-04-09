<?php

namespace Tests\Feature;

use App\Domains\Accounting\FixedAssets\FixedAsset;
use App\Domains\Accounting\FixedAssets\FixedAssetBook;
use App\Domains\Accounting\FixedAssets\FixedAssetDepreciationLine;
use App\Domains\Accounting\FixedAssets\FixedAssetDepreciationRun;
use App\Domains\Accounting\FixedAssets\FixedAssetDisposal;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class FixedAssetSchemaTest extends TestCase
{
    /** @return array{tenant: Tenant, project: Project} */
    private function createTenantAndProject(): array
    {
        $tenant = Tenant::create(['name' => 'FA Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Counterparty',
            'party_types' => ['SUPPLIER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Block A',
            'status' => 'ACTIVE',
        ]);

        return ['tenant' => $tenant, 'project' => $project];
    }

    public function test_can_create_fixed_asset_rows_with_related_records(): void
    {
        $x = $this->createTenantAndProject();
        $tenant = $x['tenant'];
        $project = $x['project'];

        $asset = FixedAsset::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'asset_code' => 'FA-001',
            'name' => 'Irrigation pump',
            'category' => 'Equipment',
            'acquisition_date' => '2026-01-15',
            'in_service_date' => '2026-02-01',
            'status' => FixedAsset::STATUS_DRAFT,
            'currency_code' => 'GBP',
            'acquisition_cost' => 5000.00,
            'residual_value' => 500.00,
            'useful_life_months' => 60,
            'depreciation_method' => FixedAsset::DEPRECIATION_STRAIGHT_LINE,
        ]);

        $book = FixedAssetBook::create([
            'tenant_id' => $tenant->id,
            'fixed_asset_id' => $asset->id,
            'book_type' => FixedAssetBook::BOOK_PRIMARY,
            'asset_cost' => 5000.00,
            'accumulated_depreciation' => 0,
            'carrying_amount' => 5000.00,
        ]);

        $run = FixedAssetDepreciationRun::create([
            'tenant_id' => $tenant->id,
            'reference_no' => 'DEP-2026-01',
            'status' => FixedAssetDepreciationRun::STATUS_DRAFT,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        FixedAssetDepreciationLine::create([
            'tenant_id' => $tenant->id,
            'depreciation_run_id' => $run->id,
            'fixed_asset_id' => $asset->id,
            'depreciation_amount' => 75.00,
            'opening_carrying_amount' => 5000.00,
            'closing_carrying_amount' => 4925.00,
            'depreciation_start' => '2026-03-01',
            'depreciation_end' => '2026-03-31',
        ]);

        $disposal = FixedAssetDisposal::create([
            'tenant_id' => $tenant->id,
            'fixed_asset_id' => $asset->id,
            'disposal_date' => '2026-12-31',
            'proceeds_amount' => 0,
            'status' => FixedAssetDisposal::STATUS_DRAFT,
        ]);

        $this->assertDatabaseHas('fixed_assets', ['id' => $asset->id, 'asset_code' => 'FA-001']);
        $this->assertDatabaseHas('fixed_asset_books', ['id' => $book->id, 'fixed_asset_id' => $asset->id]);
        $this->assertDatabaseHas('fixed_asset_depreciation_runs', ['id' => $run->id, 'reference_no' => 'DEP-2026-01']);
        $this->assertDatabaseHas('fixed_asset_depreciation_lines', ['depreciation_run_id' => $run->id, 'fixed_asset_id' => $asset->id]);
        $this->assertDatabaseHas('fixed_asset_disposals', ['id' => $disposal->id, 'fixed_asset_id' => $asset->id]);
    }

    public function test_asset_code_is_unique_per_tenant(): void
    {
        $x = $this->createTenantAndProject();
        $tenant = $x['tenant'];

        FixedAsset::create([
            'tenant_id' => $tenant->id,
            'project_id' => null,
            'asset_code' => 'FA-DUP',
            'name' => 'First',
            'category' => 'Equipment',
            'acquisition_date' => '2026-01-01',
            'status' => FixedAsset::STATUS_DRAFT,
            'currency_code' => 'GBP',
            'acquisition_cost' => 100.00,
            'residual_value' => 0,
            'useful_life_months' => 12,
            'depreciation_method' => FixedAsset::DEPRECIATION_STRAIGHT_LINE,
        ]);

        $this->expectException(QueryException::class);

        FixedAsset::create([
            'tenant_id' => $tenant->id,
            'project_id' => null,
            'asset_code' => 'FA-DUP',
            'name' => 'Second',
            'category' => 'Equipment',
            'acquisition_date' => '2026-01-02',
            'status' => FixedAsset::STATUS_DRAFT,
            'currency_code' => 'GBP',
            'acquisition_cost' => 200.00,
            'residual_value' => 0,
            'useful_life_months' => 12,
            'depreciation_method' => FixedAsset::DEPRECIATION_STRAIGHT_LINE,
        ]);
    }

    public function test_invalid_asset_status_is_rejected(): void
    {
        $x = $this->createTenantAndProject();
        $tenant = $x['tenant'];

        $this->expectException(QueryException::class);

        FixedAsset::create([
            'tenant_id' => $tenant->id,
            'project_id' => null,
            'asset_code' => 'FA-BAD',
            'name' => 'Bad status',
            'category' => 'Equipment',
            'acquisition_date' => '2026-01-01',
            'status' => 'INVALID',
            'currency_code' => 'GBP',
            'acquisition_cost' => 100.00,
            'residual_value' => 0,
            'useful_life_months' => 12,
            'depreciation_method' => FixedAsset::DEPRECIATION_STRAIGHT_LINE,
        ]);
    }

    public function test_useful_life_months_must_be_positive(): void
    {
        $x = $this->createTenantAndProject();
        $tenant = $x['tenant'];

        $this->expectException(QueryException::class);

        FixedAsset::create([
            'tenant_id' => $tenant->id,
            'project_id' => null,
            'asset_code' => 'FA-LIFE',
            'name' => 'Bad life',
            'category' => 'Equipment',
            'acquisition_date' => '2026-01-01',
            'status' => FixedAsset::STATUS_DRAFT,
            'currency_code' => 'GBP',
            'acquisition_cost' => 100.00,
            'residual_value' => 0,
            'useful_life_months' => 0,
            'depreciation_method' => FixedAsset::DEPRECIATION_STRAIGHT_LINE,
        ]);
    }
}
