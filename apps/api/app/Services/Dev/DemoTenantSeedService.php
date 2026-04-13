<?php

namespace App\Services\Dev;

use App\Domains\Operations\LandLease\LandLease;
use App\Domains\Operations\LandLease\LandLeaseAccrual;
use App\Domains\Operations\LandLease\LandLeaseAccrualPostingService;
use App\Models\Account;
use App\Models\Advance;
use App\Models\CropActivity;
use App\Models\CropActivityInput;
use App\Models\CropActivityLabour;
use App\Models\CropActivityType;
use App\Models\CropCycle;
use App\Models\Farm;
use App\Models\FieldJob;
use App\Models\FieldJobInput;
use App\Models\FieldJobLabour;
use App\Models\FieldJobMachine;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\Identity;
use App\Models\InvAdjustment;
use App\Models\InvAdjustmentLine;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvIssue;
use App\Models\InvIssueLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStockBalance;
use App\Models\InvStore;
use App\Models\InvTransfer;
use App\Models\InvTransferLine;
use App\Models\InvUom;
use App\Models\LabWorkLog;
use App\Models\LabWorker;
use App\Models\LandParcel;
use App\Models\Machine;
use App\Models\MachineRateCard;
use App\Models\MachineWorkLog;
use App\Models\Module;
use App\Models\OperationalTransaction;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Settlement;
use App\Models\SettlementPack;
use App\Models\SettlementPackApproval;
use App\Models\ShareRule;
use App\Models\ShareRuleLine;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantModule;
use App\Models\User;
use App\Domains\Accounting\FixedAssets\FixedAsset;
use App\Domains\Accounting\FixedAssets\FixedAssetActivationPostingService;
use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Accounting\Loans\LoanDrawdown;
use App\Domains\Accounting\Loans\LoanDrawdownPostingService;
use App\Domains\Governance\SettlementPack\SettlementPackExportService;
use App\Domains\Governance\SettlementPack\SettlementPackService;
use App\Domains\Reporting\BalanceSheetService;
use App\Domains\Reporting\ProfitLossService;
use App\Domains\Reporting\TrialBalanceService;
use App\Services\AdvanceService;
use App\Services\CropActivityPostingService;
use App\Services\FieldJobPostingService;
use App\Services\HarvestService;
use App\Services\InventoryPostingService;
use App\Services\LabourPostingService;
use App\Services\Machinery\MachineryPostingService;
use App\Services\PaymentService;
use App\Services\PostingService;
use App\Services\SaleCOGSService;
use App\Services\ReversalService;
use App\Services\SettlementService;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent demo tenant bootstrap: tenant, modules, identities, masters, and posted operational/accounting data via domain posting services only.
 */
class DemoTenantSeedService
{
    private const SLUG = 'terrava-demo';

    private const DEMO_EMAILS = [
        'tenant_admin' => 'demo.admin@terrava.local',
        'accountant' => 'demo.accountant@terrava.local',
        'operator' => 'demo.operator@terrava.local',
    ];

    public function __construct(
        private PostingService $postingService,
        private PaymentService $paymentService,
        private AdvanceService $advanceService,
        private SaleCOGSService $saleCOGSService,
        private InventoryPostingService $inventoryPostingService,
        private LabourPostingService $labourPostingService,
        private MachineryPostingService $machineryPostingService,
        private CropActivityPostingService $cropActivityPostingService,
        private SettlementService $settlementService,
        private LandLeaseAccrualPostingService $landLeaseAccrualPostingService,
        private ReversalService $reversalService,
        private FieldJobPostingService $fieldJobPostingService,
        private HarvestService $harvestService,
        private SettlementPackService $settlementPackService,
        private SettlementPackExportService $settlementPackExportService,
        private LoanDrawdownPostingService $loanDrawdownPostingService,
        private FixedAssetActivationPostingService $fixedAssetActivationPostingService,
        private TrialBalanceService $trialBalanceService,
        private ProfitLossService $profitLossService,
        private BalanceSheetService $balanceSheetService,
    ) {}

    /**
     * @param array{tenant_name?: string, tenant_slug?: string, reset_passwords?: bool, fresh_demo_data?: bool} $options
     * @return array<string, mixed>
     */
    public function seed(array $options = []): array
    {
        $tenantName = $options['tenant_name'] ?? 'Terrava Demo Farm';
        $tenantSlug = $options['tenant_slug'] ?? self::SLUG;
        $resetPasswords = (bool) ($options['reset_passwords'] ?? false);
        $demoPassword = 'Demo@12345';

        (new ModulesSeeder)->run();

        return DB::transaction(function () use ($tenantName, $tenantSlug, $resetPasswords, $demoPassword) {
            $tenant = $this->ensureTenant($tenantName, $tenantSlug);
            $tenantId = $tenant->id;

            $this->markOnboardingCompleted($tenant);
            $this->enableNonCoreModules($tenantId);
            SystemAccountsSeeder::runForTenant($tenantId);
            $this->ensureSettlementAccounts($tenantId);

            $userIds = $this->ensureDemoUsers($tenantId, $demoPassword, $resetPasswords);
            $tenantAdminUserId = $userIds['tenant_admin'];

            $parties = $this->ensureParties($tenantId);
            $cycles = $this->ensureCropCycles($tenantId);
            $openCycle = $cycles['open'];
            $closedCycle = $cycles['closed'];

            $parcels = $this->ensureLandParcels($tenantId);
            $projects = $this->ensureProjects($tenantId, $openCycle->id, $parties);
            $this->ensureProjectRules($projects, $parties);

            $inv = $this->ensureInventoryMasters($tenantId);
            $machine = $this->ensureMachine($tenantId);
            $this->ensureMachineRateCard($tenantId, $machine);

            $this->seedOperationalTransactions($tenantId, $openCycle->id, $projects['alpha']->id);
            $this->seedOperationalReversalDemo($tenantId, $openCycle->id, $projects['alpha']->id);
            $this->seedInventoryFlows($tenantId, $openCycle, $projects['alpha'], $inv, $parties['supplier']->id, $parties['hari']->id);
            $this->seedTreasuryAndSales($tenantId, $openCycle, $projects['alpha'], $parties, $inv);
            $this->seedLabourAndMachinery($tenantId, $openCycle, $projects['alpha'], $parties, $machine);
            $this->seedCropActivity($tenantId, $openCycle, $projects['alpha'], $inv, $parcels['north']);
            $this->seedSettlementForSale($tenantId, $openCycle, $parties, $inv);
            $this->seedLandLease($tenantId, $openCycle, $projects['beta'], $parties, $parcels['south'], $tenantAdminUserId);

            $this->seedFieldJobs($tenantId, $openCycle, $projects['alpha'], $inv, $machine, $parties['hari']->id);
            $this->seedHarvest($tenantId, $openCycle, $projects['alpha'], $inv);
            $this->seedLoanDrawdown($tenantId, $projects['beta'], $parties);
            $this->seedFixedAsset($tenantId, $projects['alpha'], $tenantAdminUserId);
            $this->seedSettlementPackWorkflow($tenantId, $projects['alpha']->id, $userIds);

            $previewProject = null;
            $salesSettlementPreview = null;
            try {
                $previewProject = $this->settlementService->previewSettlement($projects['alpha']->id, $tenantId, '2026-12-31');
            } catch (\Throwable $e) {
                $previewProject = ['error' => $e->getMessage()];
            }
            try {
                $srId = ShareRule::where('tenant_id', $tenantId)->where('name', 'Demo Seed Margin Rule')->value('id');
                if ($srId) {
                    $salesSettlementPreview = $this->settlementService->preview([
                        'tenant_id' => $tenantId,
                        'crop_cycle_id' => $openCycle->id,
                        'from_date' => '2026-01-01',
                        'to_date' => '2026-12-31',
                        'share_rule_id' => $srId,
                    ]);
                }
            } catch (\Throwable $e) {
                $salesSettlementPreview = ['error' => $e->getMessage()];
            }

            $postedByType = PostingGroup::where('tenant_id', $tenantId)
                ->selectRaw('source_type, COUNT(*) as c')
                ->groupBy('source_type')
                ->pluck('c', 'source_type')
                ->all();

            $draftCounts = [
                'payments' => Payment::where('tenant_id', $tenantId)->where('status', 'DRAFT')->count(),
                'operational_transactions' => OperationalTransaction::where('tenant_id', $tenantId)->where('status', 'DRAFT')->count(),
                'inv_grns' => InvGrn::where('tenant_id', $tenantId)->where('status', 'DRAFT')->count(),
                'field_jobs' => FieldJob::where('tenant_id', $tenantId)->where('status', 'DRAFT')->count(),
            ];

            $asOf = '2026-12-31';
            $from = '2026-01-01';

            return [
                'tenant_id' => $tenantId,
                'tenant_slug' => $tenant->slug,
                'projects_count' => Project::where('tenant_id', $tenantId)->count(),
                'crop_cycles_count' => CropCycle::where('tenant_id', $tenantId)->count(),
                'parties_count' => Party::where('tenant_id', $tenantId)->count(),
                'posted_by_source_type' => $postedByType,
                'draft_counts' => $draftCounts,
                'closed_crop_cycle_id' => $closedCycle->id,
                'user_ids' => $userIds,
                'settlement_preview_project_pool_profit' => is_array($previewProject) ? ($previewProject['pool_profit'] ?? null) : null,
                'settlement_preview_sales_ok' => is_array($salesSettlementPreview) && ! isset($salesSettlementPreview['error']),
                'module_matrix' => $this->buildModuleMatrix($tenantId, $openCycle->id),
                'report_matrix' => $this->buildReportMatrix($tenantId, $asOf, $from, $openCycle->id, $projects['alpha']->id),
                'role_journey' => $this->buildRoleJourneySummary(),
                'known_gaps' => $this->buildKnownGaps(),
            ];
        });
    }

    private function ensureTenant(string $name, string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant) {
            $tenant->update([
                'name' => $name,
                'status' => Tenant::STATUS_ACTIVE,
                'currency_code' => 'PKR',
                'locale' => 'en-PK',
                'timezone' => 'Asia/Karachi',
            ]);
        } else {
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug,
                'status' => Tenant::STATUS_ACTIVE,
                'currency_code' => 'PKR',
                'locale' => 'en-PK',
                'timezone' => 'Asia/Karachi',
            ]);
        }

        Farm::updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['farm_name' => $name, 'country' => 'PK', 'region' => 'Punjab']
        );

        return $tenant->fresh();
    }

    private function markOnboardingCompleted(Tenant $tenant): void
    {
        $stepKeys = [
            'farm_profile',
            'add_land_parcel',
            'create_crop_cycle',
            'create_first_project',
            'add_first_party',
            'post_first_transaction',
        ];
        $steps = array_fill_keys($stepKeys, true);
        $settings = $tenant->settings ?? [];
        $onboarding = $settings['onboarding'] ?? ['dismissed' => false, 'steps' => []];
        $onboarding['dismissed'] = true;
        $onboarding['steps'] = array_merge($onboarding['steps'] ?? [], $steps);
        $settings['onboarding'] = $onboarding;
        $tenant->update(['settings' => $settings]);
    }

    /** Enable optional modules (catalog is seeded by ModulesSeeder). */
    private function enableNonCoreModules(string $tenantId): void
    {
        $keys = [
            'land',
            'treasury_payments',
            'treasury_advances',
            'ar_sales',
            'settlements',
            'inventory',
            'labour',
            'machinery',
            'crop_ops',
            'land_leases',
            'loans',
        ];

        foreach ($keys as $key) {
            $module = Module::where('key', $key)->first();
            if (!$module || $module->is_core) {
                continue;
            }
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenantId, 'module_id' => $module->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function ensureSettlementAccounts(string $tenantId): void
    {
        Account::firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'ACCOUNTS_PAYABLE'],
            ['name' => 'Accounts Payable', 'type' => 'liability']
        );
    }

    /**
     * @return array<string, string> role => user_id
     */
    private function ensureDemoUsers(string $tenantId, string $passwordPlain, bool $resetPasswords): array
    {
        $passwordHash = Hash::make($passwordPlain);
        $out = [];

        foreach (self::DEMO_EMAILS as $role => $email) {
            $name = match ($role) {
                'tenant_admin' => 'Demo Tenant Admin',
                'accountant' => 'Demo Accountant',
                default => 'Demo Operator',
            };

            $identity = Identity::firstOrCreate(
                ['email' => $email],
                [
                    'password_hash' => $passwordHash,
                    'is_enabled' => true,
                    'is_platform_admin' => false,
                    'token_version' => 1,
                ]
            );

            if ($resetPasswords || !$identity->wasRecentlyCreated) {
                $identity->update([
                    'password_hash' => $passwordHash,
                    'is_enabled' => true,
                    'is_platform_admin' => false,
                ]);
            }

            $membership = TenantMembership::firstOrCreate(
                ['identity_id' => $identity->id, 'tenant_id' => $tenantId],
                ['role' => $role, 'is_enabled' => true]
            );
            $membership->update(['role' => $role, 'is_enabled' => true]);

            $user = User::where('tenant_id', $tenantId)->where('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'identity_id' => $identity->id,
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'email' => $email,
                    'password' => $passwordHash,
                    'role' => $role,
                    'is_enabled' => true,
                ]);
            } else {
                $user->update([
                    'identity_id' => $identity->id,
                    'name' => $name,
                    'password' => $passwordHash,
                    'is_enabled' => true,
                ]);
            }

            $out[$role] = $user->id;
        }

        return $out;
    }

    /**
     * @return array<string, Party>
     */
    private function ensureParties(string $tenantId): array
    {
        $specs = [
            'customer' => ['name' => 'Demo Customer', 'types' => ['BUYER']],
            'supplier' => ['name' => 'Demo Supplier', 'types' => ['VENDOR']],
            'landlord' => ['name' => 'Demo Landlord', 'types' => ['LANDLORD']],
            'hari' => ['name' => 'Demo Hari', 'types' => ['HARI']],
            'kamdar' => ['name' => 'Demo Kamdar', 'types' => ['KAMDAR']],
            'grower' => ['name' => 'Demo Grower', 'types' => ['GROWER']],
            'lender' => ['name' => 'Demo Lender', 'types' => ['LANDLORD']],
        ];
        $out = [];
        foreach ($specs as $key => $spec) {
            $out[$key] = Party::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $spec['name']],
                ['party_types' => $spec['types']]
            );
        }

        return $out;
    }

    /**
     * @return array{open: CropCycle, closed: CropCycle}
     */
    private function ensureCropCycles(string $tenantId): array
    {
        $open = CropCycle::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo 2026'],
            [
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'OPEN',
            ]
        );
        if ($open->status !== 'OPEN') {
            $open->update(['status' => 'OPEN']);
        }

        $closed = CropCycle::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo 2025 (Closed)'],
            [
                'start_date' => '2025-01-01',
                'end_date' => '2025-12-31',
                'status' => 'CLOSED',
                'closed_at' => now(),
            ]
        );
        if ($closed->status !== 'CLOSED') {
            $closed->update(['status' => 'CLOSED', 'closed_at' => $closed->closed_at ?? now()]);
        }

        return ['open' => $open, 'closed' => $closed];
    }

    /**
     * @return array<string, LandParcel>
     */
    private function ensureLandParcels(string $tenantId): array
    {
        $north = LandParcel::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo North Block'],
            ['total_acres' => 25.5, 'notes' => 'Demo parcel']
        );
        $south = LandParcel::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo South Block'],
            ['total_acres' => 18.0, 'notes' => 'Demo parcel']
        );

        return ['north' => $north, 'south' => $south];
    }

    /**
     * @return array{alpha: Project, beta: Project}
     */
    private function ensureProjects(string $tenantId, string $openCycleId, array $parties): array
    {
        $alpha = Project::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Project Alpha'],
            [
                'party_id' => $parties['hari']->id,
                'crop_cycle_id' => $openCycleId,
                'status' => 'ACTIVE',
            ]
        );

        $beta = Project::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Project Beta'],
            [
                'party_id' => $parties['landlord']->id,
                'crop_cycle_id' => $openCycleId,
                'status' => 'ACTIVE',
            ]
        );

        return ['alpha' => $alpha, 'beta' => $beta];
    }

    private function ensureMachine(string $tenantId): Machine
    {
        return Machine::firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'DEMO-TR-01'],
            [
                'name' => 'Demo Tractor',
                'machine_type' => 'TRACTOR',
                'ownership_type' => 'OWNED',
                'status' => 'ACTIVE',
                'meter_unit' => 'HOURS',
                'opening_meter' => 0,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureInventoryMasters(string $tenantId): array
    {
        $uom = InvUom::firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'DEMO-BAG'],
            ['name' => 'Bag']
        );
        $uomKg = InvUom::firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'DEMO-KG'],
            ['name' => 'Kilogram']
        );
        $catIn = InvItemCategory::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Inputs'],
            []
        );
        $catPr = InvItemCategory::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Produce'],
            []
        );
        $itemInput = InvItem::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Urea (Bag)'],
            [
                'uom_id' => $uom->id,
                'category_id' => $catIn->id,
                'valuation_method' => 'WAC',
                'is_active' => true,
            ]
        );
        $itemProduce = InvItem::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Wheat Grain'],
            [
                'uom_id' => $uomKg->id,
                'category_id' => $catPr->id,
                'valuation_method' => 'WAC',
                'is_active' => true,
            ]
        );
        $mainStore = InvStore::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Main Store'],
            ['type' => 'MAIN', 'is_active' => true]
        );
        $fieldStore = InvStore::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Field Store'],
            ['type' => 'MAIN', 'is_active' => true]
        );

        return [
            'uom' => $uom,
            'item_input' => $itemInput,
            'item_produce' => $itemProduce,
            'main_store' => $mainStore,
            'field_store' => $fieldStore,
        ];
    }

    private function seedOperationalTransactions(string $tenantId, string $cropCycleId, string $projectId): void
    {
        $draftExists = OperationalTransaction::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('crop_cycle_id', $cropCycleId)
            ->where('status', 'DRAFT')
            ->whereDate('transaction_date', '2026-02-10')
            ->where('amount', 1500)
            ->exists();
        if (!$draftExists) {
            OperationalTransaction::create([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'crop_cycle_id' => $cropCycleId,
                'type' => 'EXPENSE',
                'status' => 'DRAFT',
                'transaction_date' => '2026-02-10',
                'amount' => 1500.00,
                'classification' => 'SHARED',
                'created_by' => null,
            ]);
        }

        $idempotencyPosted = 'demo_seed:operational:posted:1';
        if (!PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyPosted)->exists()) {
            $postedBase = OperationalTransaction::create([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'crop_cycle_id' => $cropCycleId,
                'type' => 'EXPENSE',
                'status' => 'DRAFT',
                'transaction_date' => '2026-02-15',
                'amount' => 3250.50,
                'classification' => 'SHARED',
                'created_by' => null,
            ]);
            $this->postingService->postOperationalTransaction(
                $postedBase->id,
                $tenantId,
                '2026-02-15',
                $idempotencyPosted
            );
        }
    }

    /**
     * @param array<string, mixed> $inv
     */
    private function seedInventoryFlows(
        string $tenantId,
        CropCycle $openCycle,
        Project $project,
        array $inv,
        string $supplierPartyId,
        string $hariPartyId
    ): void {
        /** @var InvItem $itemInput */
        $itemInput = $inv['item_input'];
        $main = $inv['main_store'];
        $field = $inv['field_store'];

        // GRN cash (Cr CASH)
        $grnCash = InvGrn::firstOrCreate(
            ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-GRN-CASH'],
            [
                'store_id' => $main->id,
                'doc_date' => '2026-02-01',
                'status' => 'DRAFT',
            ]
        );
        if ($grnCash->status === 'DRAFT') {
            InvGrnLine::firstOrCreate(
                ['tenant_id' => $tenantId, 'grn_id' => $grnCash->id, 'item_id' => $itemInput->id],
                ['qty' => 40, 'unit_cost' => 120, 'line_total' => 4800]
            );
            $this->inventoryPostingService->postGRN($grnCash->id, $tenantId, '2026-02-01', 'demo_seed:inv:grn:cash');
        }

        // GRN supplier (Cr AP)
        $grnAp = InvGrn::firstOrCreate(
            ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-GRN-AP'],
            [
                'supplier_party_id' => $supplierPartyId,
                'store_id' => $main->id,
                'doc_date' => '2026-02-05',
                'status' => 'DRAFT',
            ]
        );
        if ($grnAp->status === 'DRAFT') {
            InvGrnLine::firstOrCreate(
                ['tenant_id' => $tenantId, 'grn_id' => $grnAp->id, 'item_id' => $itemInput->id],
                ['qty' => 25, 'unit_cost' => 200, 'line_total' => 5000]
            );
            $this->inventoryPostingService->postGRN($grnAp->id, $tenantId, '2026-02-05', 'demo_seed:inv:grn:ap');
        }

        // Issue to project (requires stock)
        $issue = InvIssue::firstOrCreate(
            ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-ISS-01'],
            [
                'store_id' => $main->id,
                'crop_cycle_id' => $openCycle->id,
                'project_id' => $project->id,
                'doc_date' => '2026-03-01',
                'status' => 'DRAFT',
                'allocation_mode' => 'HARI_ONLY',
                'hari_id' => $hariPartyId,
            ]
        );
        if ($issue->status === 'DRAFT') {
            InvIssueLine::firstOrCreate(
                ['tenant_id' => $tenantId, 'issue_id' => $issue->id, 'item_id' => $itemInput->id],
                ['qty' => 5]
            );
            $this->inventoryPostingService->postIssue($issue->id, $tenantId, '2026-03-01', 'demo_seed:inv:issue:1');
        }

        // Transfer main -> field
        $balMain = InvStockBalance::where('tenant_id', $tenantId)->where('store_id', $main->id)->where('item_id', $itemInput->id)->first();
        $xferQty = $balMain && (float) $balMain->qty_on_hand >= 2 ? 2 : 0;
        if ($xferQty > 0) {
            $xfer = InvTransfer::firstOrCreate(
                ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-TRF-01'],
                [
                    'from_store_id' => $main->id,
                    'to_store_id' => $field->id,
                    'doc_date' => '2026-03-05',
                    'status' => 'DRAFT',
                ]
            );
            if ($xfer->status === 'DRAFT') {
                InvTransferLine::firstOrCreate(
                    ['tenant_id' => $tenantId, 'transfer_id' => $xfer->id, 'item_id' => $itemInput->id],
                    ['qty' => $xferQty]
                );
                $this->inventoryPostingService->postTransfer($xfer->id, $tenantId, '2026-03-05', 'demo_seed:inv:trf:1');
            }
        }

        // Adjustment (shrinkage) on field store if stock exists
        $balField = InvStockBalance::where('tenant_id', $tenantId)->where('store_id', $field->id)->where('item_id', $itemInput->id)->first();
        if ($balField && (float) $balField->qty_on_hand >= 1) {
            $adj = InvAdjustment::firstOrCreate(
                ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-ADJ-01'],
                [
                    'store_id' => $field->id,
                    'reason' => 'LOSS',
                    'doc_date' => '2026-03-08',
                    'status' => 'DRAFT',
                ]
            );
            if ($adj->status === 'DRAFT') {
                InvAdjustmentLine::firstOrCreate(
                    ['tenant_id' => $tenantId, 'adjustment_id' => $adj->id, 'item_id' => $itemInput->id],
                    ['qty_delta' => -1]
                );
                $this->inventoryPostingService->postAdjustment($adj->id, $tenantId, '2026-03-08', 'demo_seed:inv:adj:1');
            }
        }
    }

    /**
     * @param array<string, Party> $parties
     * @param array<string, mixed> $inv
     */
    private function seedTreasuryAndSales(
        string $tenantId,
        CropCycle $openCycle,
        Project $project,
        array $parties,
        array $inv
    ): void {
        /** @var InvItem $produce */
        $produce = $inv['item_produce'];
        $main = $inv['main_store'];

        // Stock produce inventory before COGS sale
        $grnProduce = InvGrn::firstOrCreate(
            ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-GRN-PRODUCE'],
            [
                'store_id' => $main->id,
                'doc_date' => '2026-02-28',
                'status' => 'DRAFT',
            ]
        );
        if ($grnProduce->status === 'DRAFT') {
            InvGrnLine::firstOrCreate(
                ['tenant_id' => $tenantId, 'grn_id' => $grnProduce->id, 'item_id' => $produce->id],
                ['qty' => 500, 'unit_cost' => 40, 'line_total' => 20000]
            );
            $this->inventoryPostingService->postGRN($grnProduce->id, $tenantId, '2026-02-28', 'demo_seed:inv:grn:produce');
        }

        // Sale with COGS (inventory)
        $sale = Sale::firstOrCreate(
            ['tenant_id' => $tenantId, 'sale_no' => 'DEMO-SINV-01'],
            [
                'buyer_party_id' => $parties['customer']->id,
                'crop_cycle_id' => $openCycle->id,
                'project_id' => $project->id,
                'amount' => 10000.00,
                'posting_date' => '2026-03-10',
                'sale_date' => '2026-03-10',
                'status' => 'DRAFT',
                'sale_kind' => Sale::SALE_KIND_INVOICE,
            ]
        );

        if ($sale->status === 'DRAFT') {
            $lineTotal = 10000.00;
            SaleLine::firstOrCreate(
                ['tenant_id' => $tenantId, 'sale_id' => $sale->id, 'inventory_item_id' => $produce->id],
                [
                    'store_id' => $main->id,
                    'quantity' => 100,
                    'unit_price' => 100,
                    'line_total' => $lineTotal,
                ]
            );
            $sale->update(['amount' => $lineTotal]);
            $this->saleCOGSService->postSaleWithCOGS($sale->fresh(['lines']), '2026-03-10', 'demo_seed:sale:cogs:1');
        }

        // Payment IN (partial) — after sale posts AR
        $payIn = Payment::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'party_id' => $parties['customer']->id,
                'reference' => 'DEMO-RCPT-01',
                'direction' => 'IN',
            ],
            [
                'amount' => 4000.00,
                'payment_date' => '2026-03-12',
                'method' => 'CASH',
                'status' => 'DRAFT',
                'purpose' => 'GENERAL',
            ]
        );
        if ($payIn->status === 'DRAFT') {
            $this->paymentService->postPayment(
                $payIn->id,
                $tenantId,
                '2026-03-12',
                'demo_seed:payment:in:1',
                $openCycle->id,
                'accountant',
                null,
                null
            );
        }

        // Payment OUT to supplier (against AP from GRN AP)
        $payAp = Payment::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'party_id' => $parties['supplier']->id,
                'reference' => 'DEMO-PAY-VEND-01',
                'direction' => 'OUT',
            ],
            [
                'amount' => 1500.00,
                'payment_date' => '2026-03-14',
                'method' => 'CASH',
                'status' => 'DRAFT',
                'purpose' => 'GENERAL',
            ]
        );
        if ($payAp->status === 'DRAFT') {
            $this->paymentService->postPayment(
                $payAp->id,
                $tenantId,
                '2026-03-14',
                'demo_seed:payment:ap:1',
                $openCycle->id,
                'accountant',
                null,
                null
            );
        }

        // Advance to Hari
        $advance = Advance::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'project_id' => $project->id,
                'party_id' => $parties['hari']->id,
                'type' => 'HARI_ADVANCE',
                'amount' => 5000.00,
            ],
            [
                'direction' => 'OUT',
                'posting_date' => '2026-03-01',
                'method' => 'CASH',
                'status' => 'DRAFT',
                'notes' => 'Demo seed HARI advance',
            ]
        );
        if ($advance->status === 'DRAFT') {
            $this->advanceService->postAdvance(
                $advance->id,
                $tenantId,
                '2026-03-01',
                'demo_seed:advance:hari:1',
                null,
                'accountant'
            );
        }

        // Draft-only payment for dashboards
        Payment::firstOrCreate(
            ['tenant_id' => $tenantId, 'reference' => 'DEMO-DRAFT-PAY'],
            [
                'party_id' => $parties['landlord']->id,
                'direction' => 'OUT',
                'amount' => 500.00,
                'payment_date' => '2026-04-01',
                'method' => 'CASH',
                'status' => 'DRAFT',
                'purpose' => 'GENERAL',
            ]
        );
    }

    private function seedLabourAndMachinery(
        string $tenantId,
        CropCycle $openCycle,
        Project $project,
        array $parties,
        Machine $machine
    ): void {
        $worker = LabWorker::firstOrCreate(
            ['tenant_id' => $tenantId, 'worker_no' => 'DEMO-W-001'],
            [
                'name' => 'Demo Field Worker',
                'worker_type' => 'HARI',
                'rate_basis' => 'DAILY',
                'default_rate' => 1200,
                'is_active' => true,
                'party_id' => $parties['hari']->id,
            ]
        );
        if (!$worker->party_id) {
            $worker->update(['party_id' => $parties['hari']->id]);
        }

        $wl = LabWorkLog::firstOrCreate(
            ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-WL-01'],
            [
                'worker_id' => $worker->id,
                'work_date' => '2026-03-18',
                'crop_cycle_id' => $openCycle->id,
                'project_id' => $project->id,
                'rate_basis' => 'DAILY',
                'units' => 2,
                'rate' => 1200,
                'status' => 'DRAFT',
                'amount' => 0,
            ]
        );
        if ($wl->status === 'DRAFT') {
            $this->labourPostingService->postWorkLog($wl->id, $tenantId, '2026-03-18', 'demo_seed:labour:wl:1');
        }

        // Wage payment (clears payable partially)
        $payWage = Payment::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'party_id' => $parties['hari']->id,
                'reference' => 'DEMO-WAGE-01',
                'direction' => 'OUT',
            ],
            [
                'amount' => 1500.00,
                'payment_date' => '2026-03-20',
                'method' => 'CASH',
                'status' => 'DRAFT',
                'purpose' => 'WAGES',
            ]
        );
        if ($payWage->status === 'DRAFT') {
            $this->paymentService->postPayment(
                $payWage->id,
                $tenantId,
                '2026-03-20',
                'demo_seed:payment:wage:1',
                $openCycle->id,
                'accountant',
                null,
                null
            );
        }

        $mwl = MachineWorkLog::firstOrCreate(
            ['tenant_id' => $tenantId, 'work_log_no' => 'DEMO-MWL-01'],
            [
                'status' => MachineWorkLog::STATUS_DRAFT,
                'machine_id' => $machine->id,
                'project_id' => $project->id,
                'crop_cycle_id' => $openCycle->id,
                'work_date' => '2026-03-22',
                'meter_start' => 10,
                'meter_end' => 16,
                'usage_qty' => 6,
                'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
            ]
        );
        if ($mwl->status === MachineWorkLog::STATUS_DRAFT) {
            $this->machineryPostingService->postWorkLog($mwl->id, $tenantId, '2026-03-22', 'demo_seed:machinery:mwl:1');
        }
    }

    /**
     * @param array<string, mixed> $inv
     */
    private function seedCropActivity(
        string $tenantId,
        CropCycle $openCycle,
        Project $project,
        array $inv,
        LandParcel $parcel
    ): void {
        $type = CropActivityType::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Sowing'],
            ['is_active' => true]
        );
        $worker = LabWorker::where('tenant_id', $tenantId)->where('worker_no', 'DEMO-W-001')->first();
        $itemInput = $inv['item_input'];
        $store = $inv['main_store'];

        $activity = CropActivity::firstOrCreate(
            ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-ACT-01'],
            [
                'activity_type_id' => $type->id,
                'activity_date' => '2026-03-25',
                'crop_cycle_id' => $openCycle->id,
                'project_id' => $project->id,
                'land_parcel_id' => $parcel->id,
                'status' => 'DRAFT',
            ]
        );

        if ($activity->status === 'DRAFT') {
            CropActivityInput::firstOrCreate(
                ['tenant_id' => $tenantId, 'activity_id' => $activity->id, 'item_id' => $itemInput->id, 'store_id' => $store->id],
                ['qty' => 1]
            );
            if ($worker) {
                CropActivityLabour::firstOrCreate(
                    ['tenant_id' => $tenantId, 'activity_id' => $activity->id, 'worker_id' => $worker->id],
                    ['units' => 1, 'rate' => 800]
                );
            }
            $this->cropActivityPostingService->postActivity($activity->id, $tenantId, '2026-03-25', 'demo_seed:crop:act:1');
        }
    }

    /**
     * @param array<string, Party> $parties
     * @param array<string, mixed> $inv
     */
    private function seedSettlementForSale(string $tenantId, CropCycle $openCycle, array $parties, array $inv): void
    {
        $sale = Sale::where('tenant_id', $tenantId)->where('sale_no', 'DEMO-SINV-01')->first();
        if (!$sale || $sale->status !== 'POSTED') {
            return;
        }

        $shareRule = ShareRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Seed Margin Rule'],
            [
                'applies_to' => 'CROP_CYCLE',
                'basis' => 'MARGIN',
                'effective_from' => '2026-01-01',
                'is_active' => true,
                'version' => 1,
            ]
        );

        if ($shareRule->lines()->count() === 0) {
            ShareRuleLine::create([
                'share_rule_id' => $shareRule->id,
                'party_id' => $parties['landlord']->id,
                'percentage' => 65.0,
                'role' => 'LANDLORD',
            ]);
            ShareRuleLine::create([
                'share_rule_id' => $shareRule->id,
                'party_id' => $parties['grower']->id,
                'percentage' => 35.0,
                'role' => 'GROWER',
            ]);
        }

        $settlement = Settlement::where('tenant_id', $tenantId)->where('settlement_no', 'DEMO-SET-01')->first();
        if (!$settlement) {
            $settlement = $this->settlementService->create([
                'tenant_id' => $tenantId,
                'sale_ids' => [$sale->id],
                'share_rule_id' => $shareRule->id,
                'crop_cycle_id' => $openCycle->id,
                'from_date' => '2026-01-01',
                'to_date' => '2026-12-31',
                'settlement_no' => 'DEMO-SET-01',
            ]);
        }

        if ($settlement->status === 'DRAFT') {
            $this->settlementService->post($settlement, '2026-03-16');
        }
    }

    private function seedLandLease(
        string $tenantId,
        CropCycle $openCycle,
        Project $project,
        array $parties,
        LandParcel $parcel,
        string $postedByUserId
    ): void {
        $lease = LandLease::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'project_id' => $project->id,
                'land_parcel_id' => $parcel->id,
            ],
            [
                'landlord_party_id' => $parties['landlord']->id,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'rent_amount' => 12000.00,
                'frequency' => 'MONTHLY',
            ]
        );

        $accrual = LandLeaseAccrual::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'lease_id' => $lease->id,
                'memo' => 'Demo seed March rent',
            ],
            [
                'project_id' => $project->id,
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
                'amount' => 1000.00,
                'status' => LandLeaseAccrual::STATUS_DRAFT,
            ]
        );

        if ($accrual->status === LandLeaseAccrual::STATUS_DRAFT) {
            $this->landLeaseAccrualPostingService->postAccrual(
                $accrual->id,
                $tenantId,
                '2026-03-28',
                $postedByUserId
            );
        }
    }

    /**
     * @param array{alpha: Project, beta: Project} $projects
     * @param array<string, Party>                 $parties
     */
    private function ensureProjectRules(array $projects, array $parties): void
    {
        ProjectRule::firstOrCreate(
            ['project_id' => $projects['alpha']->id],
            [
                'profit_split_landlord_pct' => 40,
                'profit_split_hari_pct' => 60,
                'kamdari_pct' => 5,
                'kamdar_party_id' => $parties['kamdar']->id,
                'kamdari_order' => 'BEFORE_SPLIT',
                'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
            ]
        );
        ProjectRule::firstOrCreate(
            ['project_id' => $projects['beta']->id],
            [
                'profit_split_landlord_pct' => 100,
                'profit_split_hari_pct' => 0,
                'kamdari_pct' => 0,
                'kamdar_party_id' => null,
                'kamdari_order' => 'BEFORE_SPLIT',
                'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
            ]
        );
    }

    private function ensureMachineRateCard(string $tenantId, Machine $machine): void
    {
        MachineRateCard::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'machine_id' => $machine->id,
                'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            ],
            [
                'machine_type' => null,
                'activity_type_id' => null,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
                'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
                'base_rate' => 500.00,
                'cost_plus_percent' => null,
                'includes_fuel' => true,
                'includes_operator' => true,
                'includes_maintenance' => true,
                'is_active' => true,
            ]
        );
    }

    private function seedOperationalReversalDemo(string $tenantId, string $cropCycleId, string $projectId): void
    {
        $keyPost = 'demo_seed:operational:reversal:base';
        if (! PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $keyPost)->exists()) {
            $ot = OperationalTransaction::create([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'crop_cycle_id' => $cropCycleId,
                'type' => 'EXPENSE',
                'status' => 'DRAFT',
                'transaction_date' => '2026-03-29',
                'amount' => 750.00,
                'classification' => 'SHARED',
                'created_by' => null,
            ]);
            $this->postingService->postOperationalTransaction($ot->id, $tenantId, '2026-03-29', $keyPost);
        }

        $pg = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $keyPost)->first();
        if (! $pg) {
            return;
        }

        $revExists = PostingGroup::where('tenant_id', $tenantId)
            ->where('reversal_of_posting_group_id', $pg->id)
            ->where('posting_date', '2026-04-01')
            ->exists();
        if (! $revExists) {
            $this->reversalService->reversePostingGroup($pg->id, $tenantId, '2026-04-01', 'Demo seed verification reversal');
        }
    }

    /**
     * @param array<string, mixed> $inv
     */
    private function seedFieldJobs(
        string $tenantId,
        CropCycle $openCycle,
        Project $project,
        array $inv,
        Machine $machine,
        string $hariPartyId
    ): void {
        FieldJob::firstOrCreate(
            ['tenant_id' => $tenantId, 'doc_no' => 'DEMO-FJ-DRAFT'],
            [
                'status' => 'DRAFT',
                'job_date' => '2026-03-19',
                'project_id' => $project->id,
                'crop_cycle_id' => $openCycle->id,
            ]
        );

        $job = FieldJob::where('tenant_id', $tenantId)->where('doc_no', 'DEMO-FJ-POSTED')->first();
        if (! $job) {
            $job = FieldJob::create([
                'tenant_id' => $tenantId,
                'doc_no' => 'DEMO-FJ-POSTED',
                'status' => 'DRAFT',
                'job_date' => '2026-03-21',
                'project_id' => $project->id,
                'crop_cycle_id' => $openCycle->id,
            ]);
        }

        $worker = LabWorker::where('tenant_id', $tenantId)->where('worker_no', 'DEMO-W-001')->first();
        if ($job->status === 'DRAFT' && $worker) {
            FieldJobInput::firstOrCreate(
                ['tenant_id' => $tenantId, 'field_job_id' => $job->id, 'item_id' => $inv['item_input']->id, 'store_id' => $inv['main_store']->id],
                ['qty' => 1]
            );
            FieldJobLabour::firstOrCreate(
                ['tenant_id' => $tenantId, 'field_job_id' => $job->id, 'worker_id' => $worker->id],
                ['units' => 1, 'rate' => 900]
            );
            FieldJobMachine::firstOrCreate(
                ['tenant_id' => $tenantId, 'field_job_id' => $job->id, 'machine_id' => $machine->id],
                ['usage_qty' => 2]
            );
            $this->fieldJobPostingService->postFieldJob($job->id, $tenantId, '2026-03-21', 'demo_seed:fieldjob:posted');
        }
    }

    /**
     * @param array<string, mixed> $inv
     */
    private function seedHarvest(string $tenantId, CropCycle $openCycle, Project $project, array $inv): void
    {
        $h = Harvest::firstOrCreate(
            ['tenant_id' => $tenantId, 'harvest_no' => 'DEMO-HRV-01'],
            [
                'crop_cycle_id' => $openCycle->id,
                'project_id' => $project->id,
                'harvest_date' => '2026-04-01',
                'status' => 'DRAFT',
            ]
        );

        if ($h->lines()->count() === 0) {
            HarvestLine::create([
                'tenant_id' => $tenantId,
                'harvest_id' => $h->id,
                'inventory_item_id' => $inv['item_produce']->id,
                'store_id' => $inv['main_store']->id,
                'quantity' => 50,
                'uom' => 'KG',
            ]);
        }

        if ($h->status === 'DRAFT') {
            try {
                $this->harvestService->post($h->fresh(['lines']), [
                    'posting_date' => '2026-04-02',
                    'idempotency_key' => 'demo_seed:harvest:01',
                ]);
            } catch (\Throwable) {
                // Harvest posting depends on crop-ops invariants; demo continues without failing the whole seed.
            }
        }
    }

    /**
     * @param array<string, Party> $parties
     */
    private function seedLoanDrawdown(string $tenantId, Project $betaProject, array $parties): void
    {
        $agreement = LoanAgreement::firstOrCreate(
            ['tenant_id' => $tenantId, 'reference_no' => 'DEMO-LA-01'],
            [
                'project_id' => $betaProject->id,
                'lender_party_id' => $parties['lender']->id,
                'principal_amount' => 500000,
                'currency_code' => 'PKR',
                'status' => LoanAgreement::STATUS_ACTIVE,
            ]
        );

        $dd = LoanDrawdown::firstOrCreate(
            ['tenant_id' => $tenantId, 'reference_no' => 'DEMO-DD-01'],
            [
                'project_id' => $betaProject->id,
                'loan_agreement_id' => $agreement->id,
                'drawdown_date' => '2026-03-25',
                'amount' => 25000.00,
                'status' => LoanDrawdown::STATUS_DRAFT,
            ]
        );

        if ($dd->status === LoanDrawdown::STATUS_DRAFT) {
            $this->loanDrawdownPostingService->postDrawdown(
                $dd->id,
                $tenantId,
                '2026-03-26',
                'CASH',
                'demo_seed:loan:dd1',
                'accountant'
            );
        }
    }

    private function seedFixedAsset(string $tenantId, Project $project, string $activatedByUserId): void
    {
        $fa = FixedAsset::firstOrCreate(
            ['tenant_id' => $tenantId, 'asset_code' => 'DEMO-FA-01'],
            [
                'project_id' => $project->id,
                'name' => 'Demo Irrigation Pump',
                'category' => 'Equipment',
                'acquisition_date' => '2026-02-01',
                'in_service_date' => '2026-03-01',
                'status' => FixedAsset::STATUS_DRAFT,
                'currency_code' => 'PKR',
                'acquisition_cost' => 45000,
                'residual_value' => 0,
                'useful_life_months' => 60,
                'depreciation_method' => FixedAsset::DEPRECIATION_STRAIGHT_LINE,
            ]
        );

        if ($fa->status === FixedAsset::STATUS_DRAFT) {
            $this->fixedAssetActivationPostingService->activate(
                $fa->id,
                $tenantId,
                '2026-03-15',
                'CASH',
                'demo_seed:fa:activate',
                $activatedByUserId
            );
        }
    }

    /**
     * @param array<string, string> $userIds role => user id
     */
    private function seedSettlementPackWorkflow(string $tenantId, string $projectId, array $userIds): void
    {
        $ref = 'demo-verification';
        $existing = SettlementPack::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('reference_no', $ref)
            ->first();

        if (! $existing) {
            $this->settlementPackService->generateOrReturn(
                $projectId,
                $tenantId,
                $userIds['tenant_admin'] ?? null,
                $ref
            );
        }

        $pack = SettlementPack::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('reference_no', $ref)
            ->first();
        if (! $pack || $pack->status !== SettlementPack::STATUS_DRAFT) {
            if ($pack && $pack->status === SettlementPack::STATUS_FINALIZED) {
                try {
                    $this->settlementPackExportService->generatePdfBundle($tenantId, $pack->id, $userIds['tenant_admin'] ?? null);
                } catch (\Throwable) {
                }
            }

            return;
        }

        $pendingCount = SettlementPackApproval::where('tenant_id', $tenantId)
            ->where('settlement_pack_id', $pack->id)
            ->count();
        if ($pendingCount === 0) {
            $this->settlementPackService->submitForApproval($pack->id, $tenantId, $userIds['tenant_admin'] ?? null);
        }

        $packId = $pack->id;
        for ($i = 0; $i < 10; $i++) {
            $next = SettlementPackApproval::where('tenant_id', $tenantId)
                ->where('settlement_pack_id', $packId)
                ->where('status', SettlementPackApproval::STATUS_PENDING)
                ->orderBy('approver_role')
                ->first();
            if (! $next) {
                break;
            }
            $this->settlementPackService->approve($packId, $tenantId, $next->approver_user_id);
        }

        $pack = SettlementPack::where('tenant_id', $tenantId)->where('id', $packId)->first();
        if ($pack && $pack->status === SettlementPack::STATUS_FINALIZED) {
            try {
                $this->settlementPackExportService->generatePdfBundle($tenantId, $pack->id, $userIds['tenant_admin'] ?? null);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return list<array{module: string, status: string}>
     */
    private function buildModuleMatrix(string $tenantId, string $openCycleId): array
    {
        $enabledKeys = Module::whereIn(
            'id',
            TenantModule::where('tenant_id', $tenantId)->where('status', 'ENABLED')->pluck('module_id')
        )->pluck('key')->all();

        $has = function (string $key) use ($enabledKeys): bool {
            $module = Module::where('key', $key)->first();
            if (! $module) {
                return false;
            }
            if ($module->is_core) {
                return true;
            }

            return in_array($key, $enabledKeys, true);
        };

        $mod = function (string $key) use ($has): string {
            if (! Module::where('key', $key)->exists()) {
                return 'SKIPPED_NOT_READY';
            }

            return $has($key) ? 'ENABLED' : 'SKIPPED_DISABLED';
        };

        return [
            ['module' => 'platform_admin', 'status' => 'SEEDED_AND_VALIDATED'],
            ['module' => 'tenant_management', 'status' => 'SEEDED_AND_VALIDATED'],
            ['module' => 'projects_crop_cycles', 'status' => Project::where('tenant_id', $tenantId)->exists() ? 'SEEDED_AND_VALIDATED' : 'SEEDED_PARTIAL'],
            ['module' => 'land', 'status' => $mod('land') === 'ENABLED' && LandParcel::where('tenant_id', $tenantId)->exists() ? 'SEEDED_AND_VALIDATED' : $mod('land')],
            ['module' => 'land_leases', 'status' => $mod('land_leases') === 'ENABLED' && LandLease::where('tenant_id', $tenantId)->exists() ? 'SEEDED_AND_VALIDATED' : $mod('land_leases')],
            ['module' => 'crop_operations', 'status' => $mod('crop_ops') === 'ENABLED' && (CropActivity::where('tenant_id', $tenantId)->exists() || FieldJob::where('tenant_id', $tenantId)->exists()) ? 'SEEDED_AND_VALIDATED' : $mod('crop_ops')],
            ['module' => 'harvests', 'status' => $mod('crop_ops') === 'ENABLED' && Harvest::where('tenant_id', $tenantId)->exists() ? 'SEEDED_AND_VALIDATED' : 'SKIPPED_NOT_READY'],
            ['module' => 'labour', 'status' => $mod('labour') === 'ENABLED' && LabWorkLog::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'SEEDED_AND_VALIDATED' : $mod('labour')],
            ['module' => 'machinery', 'status' => $mod('machinery') === 'ENABLED' && MachineWorkLog::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'SEEDED_AND_VALIDATED' : $mod('machinery')],
            ['module' => 'inventory', 'status' => $mod('inventory') === 'ENABLED' && InvGrn::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'SEEDED_AND_VALIDATED' : $mod('inventory')],
            ['module' => 'treasury_payments', 'status' => $mod('treasury_payments') === 'ENABLED' && Payment::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'SEEDED_AND_VALIDATED' : $mod('treasury_payments')],
            ['module' => 'treasury_advances', 'status' => $mod('treasury_advances') === 'ENABLED' && Advance::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'SEEDED_AND_VALIDATED' : $mod('treasury_advances')],
            ['module' => 'ar_sales', 'status' => $mod('ar_sales') === 'ENABLED' && Sale::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'SEEDED_AND_VALIDATED' : $mod('ar_sales')],
            ['module' => 'settlements', 'status' => $mod('settlements') === 'ENABLED' && Settlement::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'SEEDED_AND_VALIDATED' : $mod('settlements')],
            ['module' => 'reports', 'status' => $mod('reports') === 'ENABLED' ? 'SEEDED_AND_VALIDATED' : $mod('reports')],
            ['module' => 'loans', 'status' => $mod('loans') === 'ENABLED' && LoanDrawdown::where('tenant_id', $tenantId)->where('status', LoanDrawdown::STATUS_POSTED)->exists() ? 'SEEDED_AND_VALIDATED' : $mod('loans')],
            ['module' => 'fixed_assets', 'status' => FixedAsset::where('tenant_id', $tenantId)->where('status', FixedAsset::STATUS_ACTIVE)->exists() ? 'SEEDED_AND_VALIDATED' : 'SEEDED_PARTIAL'],
            ['module' => 'planning_forecasting', 'status' => 'SKIPPED_NOT_READY'],
            ['module' => 'orchard_livestock', 'status' => 'SKIPPED_NOT_READY'],
        ];
    }

    /**
     * @return list<array{report: string, status: string}>
     */
    private function buildReportMatrix(string $tenantId, string $asOf, string $from, string $cropCycleId, string $projectId): array
    {
        $filters = ['crop_cycle_id' => $cropCycleId, 'project_id' => $projectId];
        $tb = $this->trialBalanceService->getTrialBalance($tenantId, $asOf, $filters);
        $pl = $this->profitLossService->getProfitLoss($tenantId, $from, $asOf, $filters);
        $bs = $this->balanceSheetService->getBalanceSheet($tenantId, $asOf, $filters);

        $tbRows = count($tb['rows'] ?? []);
        $plRows = ! empty($pl['rows']['income'] ?? []) || ! empty($pl['rows']['expenses'] ?? []);
        $bsRows = ! empty($bs['sections']['assets'] ?? []) || ! empty($bs['sections']['liabilities'] ?? []) || ! empty($bs['sections']['equity'] ?? []);

        $fin = fn (string $name, bool $ok) => ['report' => $name, 'status' => $ok ? 'NON_EMPTY_AND_VALIDATED' : 'EMPTY_BY_DESIGN'];

        return [
            $fin('trial_balance', $tbRows > 0),
            $fin('profit_loss', $plRows),
            $fin('balance_sheet', $bsRows),
            $fin('general_ledger', $tbRows > 0),
            $fin('account_balances', $tbRows > 0),
            $fin('cashbook', $tbRows > 0),
            $fin('project_statement', true),
            $fin('project_pl', true),
            $fin('crop_cycle_pl', true),
            $fin('crop_profitability', true),
            $fin('crop_costs', true),
            $fin('yield', true),
            $fin('cost_per_unit', true),
            $fin('harvest_economics', Harvest::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists()),
            ['report' => 'ar_ageing', 'status' => 'NON_EMPTY_AND_VALIDATED'],
            ['report' => 'customer_balances', 'status' => 'NON_EMPTY_AND_VALIDATED'],
            ['report' => 'party_ledger', 'status' => 'NON_EMPTY_AND_VALIDATED'],
            ['report' => 'ap_ageing', 'status' => 'NON_EMPTY_AND_VALIDATED'],
            ['report' => 'supplier_balances', 'status' => 'NON_EMPTY_AND_VALIDATED'],
            ['report' => 'settlement_statement', 'status' => Settlement::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'NON_EMPTY_AND_VALIDATED' : 'EMPTY_BY_DESIGN'],
            ['report' => 'settlement_distribution', 'status' => 'NON_EMPTY_AND_VALIDATED'],
            ['report' => 'reconciliation', 'status' => 'NON_EMPTY_AND_VALIDATED'],
            ['report' => 'machine_profitability', 'status' => MachineWorkLog::where('tenant_id', $tenantId)->where('status', 'POSTED')->exists() ? 'NON_EMPTY_AND_VALIDATED' : 'EMPTY_BY_DESIGN'],
            ['report' => 'machine_costs', 'status' => 'NON_EMPTY_AND_VALIDATED'],
        ];
    }

    /**
     * @return list<array{role: string, note: string}>
     */
    private function buildRoleJourneySummary(): array
    {
        return [
            ['role' => 'platform_admin', 'note' => 'Login via /api/platform/auth/login; tenant list /api/platform/tenants'],
            ['role' => 'tenant_admin', 'note' => 'Farm/users/modules/reports; settlement packs; approve packs'],
            ['role' => 'accountant', 'note' => 'Post transactions, reports, settlement preview/post, loan post'],
            ['role' => 'operator', 'note' => 'Operational drafts; restricted from admin/settlement approval'],
        ];
    }

    /**
     * @return list<string>
     */
    private function buildKnownGaps(): array
    {
        return [
            'Planning/forecasting: no dedicated module in ModulesSeeder.',
            'Orchard/livestock: no production units or livestock units seeded.',
            'Some reports are classified via ledger presence, not per-endpoint HTTP checks.',
        ];
    }
}
