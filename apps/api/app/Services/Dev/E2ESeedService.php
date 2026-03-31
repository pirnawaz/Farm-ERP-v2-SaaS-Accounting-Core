<?php

namespace App\Services\Dev;

use App\Models\CropCycle;
use App\Models\Farm;
use App\Models\Identity;
use App\Models\OperationalTransaction;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserInvitation;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class E2ESeedService
{
    public function __construct(
        private \App\Services\PostingService $postingService,
        private \App\Services\ReversalService $reversalService,
        private \App\Services\SystemPartyService $partyService
    ) {}

    /**
     * Idempotent E2E seed: ensure tenant, users, OPEN cycle, project, DRAFT + POSTED + reversal-ready operational transactions.
     * Returns array of IDs for .seed-state.json.
     *
     * @param string|null $tenantId Optional existing tenant ID
     * @param string $tenantName Name to find or create (default "E2E Farm")
     * @return array<string, string>
     */
    public function seed(?string $tenantId = null, string $tenantName = 'E2E Farm'): array
    {
        return DB::transaction(function () use ($tenantId, $tenantName) {
            $tenant = $this->resolveTenant($tenantId, $tenantName);
            $tenantId = $tenant->id;

            $this->markOnboardingCompleted($tenant);

            $this->ensureBootstrapAccounts($tenantId);
            $userIds = $this->ensureUsersPerRole($tenantId);
            $userIds['platform_admin'] = $this->ensurePlatformAdminUser();
            $this->ensureMultiTenantIdentityForE2E($tenantId);

            $openCycle = $this->ensureOpenCropCycle($tenantId);
            $project = $this->ensureProject($tenantId, $openCycle->id);
            $draftTxn = $this->ensureDraftTransaction($tenantId, $openCycle->id, $project->id);
            [$postedTxn, $postedPg] = $this->ensurePostedTransaction($tenantId, $openCycle->id, $project->id);
            [$reversalTxn, $reversalPg] = $this->ensureReversalReadyTransaction($tenantId, $openCycle->id, $project->id);

            [$closedCycle, $draftInClosedCycleTxn] = $this->ensureClosedCropCycleWithDraft($tenantId);

            $inviteToken = $this->ensureE2EInviteToken($tenantId, $userIds['tenant_admin']);

            $this->persistSeedState($tenantId, [
                'draft_transaction_id' => $draftTxn->id,
                'posted_transaction_id' => $postedTxn->id,
                'posted_transaction_posting_group_id' => $postedPg->id,
                'reversal_transaction_id' => $reversalTxn->id,
                'reversal_posting_group_id' => $reversalPg->id,
                'closed_crop_cycle_id' => $closedCycle->id,
                'draft_in_closed_cycle_transaction_id' => $draftInClosedCycleTxn->id,
                'tenant_admin_user_id' => $userIds['tenant_admin'],
                'accountant_user_id' => $userIds['accountant'],
                'operator_user_id' => $userIds['operator'],
                'platform_admin_user_id' => $userIds['platform_admin'],
            ]);

            return [
                'tenant_id' => $tenantId,
                'invite_token' => $inviteToken,
                'crop_cycle_id' => $openCycle->id,
                'open_crop_cycle_id' => $openCycle->id,
                'closed_crop_cycle_id' => $closedCycle->id,
                'project_id' => $project->id,
                'draft_transaction_id' => $draftTxn->id,
                'posted_transaction_id' => $postedTxn->id,
                'posted_transaction_posting_group_id' => $postedPg->id,
                'reversal_transaction_id' => $reversalTxn->id,
                'reversal_posting_group_id' => $reversalPg->id,
                'draft_in_closed_cycle_transaction_id' => $draftInClosedCycleTxn->id,
                'tenant_admin_user_id' => $userIds['tenant_admin'],
                'accountant_user_id' => $userIds['accountant'],
                'operator_user_id' => $userIds['operator'],
                'platform_admin_user_id' => $userIds['platform_admin'],
                'draft_machinery_service_id' => null,
                'posted_machinery_service_id' => null,
                'posted_machinery_posting_group_id' => null,
            ];
        });
    }

    /**
     * Create an E2E invite for e2e-invited@e2e.local (accountant) so accept-invite E2E can run.
     * Returns the plain token or null if creation failed (e.g. user already exists).
     */
    private function ensureE2EInviteToken(string $tenantId, string $invitedByUserId): ?string
    {
        $email = 'e2e-invited@e2e.local';
        if (User::where('tenant_id', $tenantId)->where('email', $email)->exists()) {
            $existing = UserInvitation::findExistingNotExpired($tenantId, $email);
            return $existing?->token_plain;
        }
        [$plain] = UserInvitation::createOrReuseInvitation($tenantId, $email, 'accountant', $invitedByUserId, 168);
        return $plain;
    }

    /**
     * Return current seed state without creating new records (for GET /api/dev/e2e/seed-state).
     */
    public function getSeedState(?string $tenantId = null, string $tenantName = 'E2E Farm'): ?array
    {
        $tenant = $this->resolveTenant($tenantId, $tenantName);
        $tenantId = $tenant->id;

        $draftId = $this->getStoredValue($tenantId, 'draft_transaction_id');
        $postedId = $this->getStoredValue($tenantId, 'posted_transaction_id');
        $postedPgId = $this->getStoredValue($tenantId, 'posted_transaction_posting_group_id');
        $reversalId = $this->getStoredValue($tenantId, 'reversal_transaction_id');
        $reversalPgId = $this->getStoredValue($tenantId, 'reversal_posting_group_id');
        $closedCycleId = $this->getStoredValue($tenantId, 'closed_crop_cycle_id');
        $draftInClosedId = $this->getStoredValue($tenantId, 'draft_in_closed_cycle_transaction_id');
        $tenantAdminUserId = $this->getStoredValue($tenantId, 'tenant_admin_user_id');
        $accountantUserId = $this->getStoredValue($tenantId, 'accountant_user_id');
        $operatorUserId = $this->getStoredValue($tenantId, 'operator_user_id');
        $platformAdminUserId = $this->getStoredValue($tenantId, 'platform_admin_user_id');

        if (!$draftId || !$postedId || !$postedPgId || !$reversalId || !$reversalPgId) {
            return null;
        }

        $openCycle = CropCycle::where('tenant_id', $tenantId)->where('name', 'like', 'E2E Cycle%')->where('status', 'OPEN')->first();
        $project = Project::where('tenant_id', $tenantId)->where('name', 'like', 'E2E Project%')->first();

        return [
            'tenant_id' => $tenantId,
            'crop_cycle_id' => $openCycle?->id,
            'open_crop_cycle_id' => $openCycle?->id,
            'closed_crop_cycle_id' => $closedCycleId,
            'project_id' => $project?->id,
            'draft_transaction_id' => $draftId,
            'posted_transaction_id' => $postedId,
            'posted_transaction_posting_group_id' => $postedPgId,
            'reversal_transaction_id' => $reversalId,
            'reversal_posting_group_id' => $reversalPgId,
            'draft_in_closed_cycle_transaction_id' => $draftInClosedId,
            'tenant_admin_user_id' => $tenantAdminUserId,
            'accountant_user_id' => $accountantUserId,
            'operator_user_id' => $operatorUserId,
            'platform_admin_user_id' => $platformAdminUserId,
            'draft_machinery_service_id' => null,
            'posted_machinery_service_id' => null,
            'posted_machinery_posting_group_id' => null,
        ];
    }

    /**
     * Mark tenant onboarding as completed (dismissed + all steps true) so the app does not block navigation.
     * Idempotent: safe to run on every seed.
     */
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

    private function resolveTenant(?string $tenantId, string $tenantName): Tenant
    {
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                if ($tenant->status !== 'active') {
                    $tenant->update(['status' => 'active']);
                }
                return $tenant;
            }
        }

        $tenant = Tenant::where('name', $tenantName)->first();
        if ($tenant) {
            if ($tenant->status !== 'active') {
                $tenant->update(['status' => 'active']);
            }
            return $tenant;
        }

        $tenant = Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => $tenantName,
            'status' => 'active',
            'currency_code' => 'PKR',
            'locale' => 'en-PK',
            'timezone' => 'Asia/Karachi',
        ]);
        Farm::firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['farm_name' => $tenant->name]
        );
        return $tenant;
    }

    private function ensureBootstrapAccounts(string $tenantId): void
    {
        SystemAccountsSeeder::runForTenant($tenantId);
    }

    /**
     * Ensure one enabled user per tenant role with Identity + TenantMembership so unified login works.
     * Does not create platform_admin (use ensurePlatformAdminUser).
     * @return array<string, string>
     */
    private function ensureUsersPerRole(string $tenantId): array
    {
        $roles = ['tenant_admin', 'accountant', 'operator'];
        $userIds = [];
        foreach ($roles as $role) {
            $userIds[$role] = $this->ensureIdentityMembershipAndUserForRole($tenantId, $role);
        }
        return $userIds;
    }

    /**
     * Ensure Identity, TenantMembership, and User exist for a tenant role so unified login succeeds.
     * Returns user_id for the tenant user.
     */
    private function ensureIdentityMembershipAndUserForRole(string $tenantId, string $role): string
    {
        $email = "e2e-{$role}@e2e.local";
        $passwordHash = Hash::make('password');
        $name = 'E2E ' . str_replace('_', ' ', ucfirst($role));

        $identity = Identity::firstOrCreate(
            ['email' => $email],
            [
                'password_hash' => $passwordHash,
                'is_enabled' => true,
                'is_platform_admin' => false,
                'token_version' => 1,
            ]
        );
        if ($identity->wasRecentlyCreated === false) {
            $identity->update(['password_hash' => $passwordHash, 'is_enabled' => true]);
        }

        $membership = TenantMembership::where('identity_id', $identity->id)
            ->where('tenant_id', $tenantId)
            ->first();
        if (!$membership) {
            TenantMembership::create([
                'identity_id' => $identity->id,
                'tenant_id' => $tenantId,
                'role' => $role,
                'is_enabled' => true,
            ]);
        } else {
            $membership->update(['role' => $role, 'is_enabled' => true]);
        }

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
                'is_enabled' => true,
            ]);
        }

        return $user->id;
    }

    /**
     * Ensure one identity (e2e-multi@e2e.local) with two tenant memberships so select-tenant E2E can run.
     * Creates second tenant "E2E Farm 2" if needed, then Identity + 2 TenantMemberships + 2 Users.
     */
    private function ensureMultiTenantIdentityForE2E(string $firstTenantId): void
    {
        $secondTenant = $this->resolveTenant(null, 'E2E Farm 2');
        $secondTenantId = $secondTenant->id;
        $this->markOnboardingCompleted($secondTenant);

        $email = 'e2e-multi@e2e.local';
        $passwordHash = Hash::make('password');

        $identity = Identity::firstOrCreate(
            ['email' => $email],
            [
                'password_hash' => $passwordHash,
                'is_enabled' => true,
                'is_platform_admin' => false,
                'token_version' => 1,
            ]
        );
        if (!$identity->wasRecentlyCreated) {
            $identity->update(['password_hash' => $passwordHash, 'is_enabled' => true]);
        }

        foreach ([$firstTenantId => 'E2E Farm', $secondTenantId => 'E2E Farm 2'] as $tid => $_name) {
            $membership = TenantMembership::firstOrCreate(
                ['identity_id' => $identity->id, 'tenant_id' => $tid],
                ['role' => 'tenant_admin', 'is_enabled' => true]
            );
            $membership->update(['role' => 'tenant_admin', 'is_enabled' => true]);

            $user = User::firstOrCreate(
                ['tenant_id' => $tid, 'email' => $email],
                [
                    'identity_id' => $identity->id,
                    'name' => 'E2E Multi',
                    'password' => $passwordHash,
                    'role' => 'tenant_admin',
                    'is_enabled' => true,
                ]
            );
            $user->update(['identity_id' => $identity->id, 'is_enabled' => true]);
        }
    }

    /**
     * Ensure one enabled platform_admin user (tenant_id null). Return user_id.
     */
    private function ensurePlatformAdminUser(): string
    {
        $email = 'e2e-platform_admin@e2e.local';
        $user = User::whereNull('tenant_id')->where('email', $email)->where('role', 'platform_admin')->first();
        if (!$user) {
            $user = User::create([
                'tenant_id' => null,
                'name' => 'E2E Platform Admin',
                'email' => $email,
                'password' => Hash::make('password'),
                'role' => 'platform_admin',
                'is_enabled' => true,
            ]);
        } else {
            if (!$user->is_enabled) {
                $user->update(['is_enabled' => true]);
            }
        }
        return $user->id;
    }

    private function ensureOpenCropCycle(string $tenantId): CropCycle
    {
        $cycle = CropCycle::where('tenant_id', $tenantId)->where('name', 'E2E Cycle')->first();
        if ($cycle) {
            if ($cycle->status !== 'OPEN') {
                $nextName = 'E2E Cycle (2)';
                $cycle = CropCycle::create([
                    'tenant_id' => $tenantId,
                    'name' => $nextName,
                    'start_date' => now()->startOfYear(),
                    'end_date' => now()->endOfYear(),
                    'status' => 'OPEN',
                ]);
            }
            return $cycle;
        }

        return CropCycle::create([
            'tenant_id' => $tenantId,
            'name' => 'E2E Cycle',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'status' => 'OPEN',
        ]);
    }

    /**
     * Ensure a CLOSED crop cycle exists with one DRAFT transaction (for E2E "posting blocked" test).
     * Returns [closedCycle, draftTransaction].
     */
    private function ensureClosedCropCycleWithDraft(string $tenantId): array
    {
        $storedClosedId = $this->getStoredValue($tenantId, 'closed_crop_cycle_id');
        $storedDraftId = $this->getStoredValue($tenantId, 'draft_in_closed_cycle_transaction_id');
        if ($storedClosedId && $storedDraftId) {
            $cycle = CropCycle::where('id', $storedClosedId)->where('tenant_id', $tenantId)->where('status', 'CLOSED')->first();
            $txn = OperationalTransaction::where('id', $storedDraftId)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->first();
            if ($cycle && $txn) {
                return [$cycle, $txn];
            }
        }

        $closedCycle = CropCycle::where('tenant_id', $tenantId)->where('name', 'E2E Cycle (Closed)')->first();
        if (!$closedCycle) {
            $closedCycle = CropCycle::create([
                'tenant_id' => $tenantId,
                'name' => 'E2E Cycle (Closed)',
                'start_date' => now()->startOfYear()->subYear(),
                'end_date' => now()->endOfYear()->subYear(),
                'status' => 'CLOSED',
                'closed_at' => now(),
            ]);
        } else {
            if ($closedCycle->status !== 'CLOSED') {
                $closedCycle->update(['status' => 'CLOSED', 'closed_at' => $closedCycle->closed_at ?? now()]);
            }
        }

        $project = Project::where('tenant_id', $tenantId)
            ->where('crop_cycle_id', $closedCycle->id)
            ->where('name', 'E2E Project (Closed)')
            ->first();
        if (!$project) {
            $party = Party::where('tenant_id', $tenantId)->where('name', 'E2E Party')->first();
            if (!$party) {
                $party = Party::create([
                    'tenant_id' => $tenantId,
                    'name' => 'E2E Party',
                    'party_types' => ['HARI'],
                ]);
            }
            $project = Project::create([
                'tenant_id' => $tenantId,
                'name' => 'E2E Project (Closed)',
                'party_id' => $party->id,
                'crop_cycle_id' => $closedCycle->id,
                'status' => 'ACTIVE',
            ]);
        }

        $draftTxn = OperationalTransaction::where('tenant_id', $tenantId)
            ->where('crop_cycle_id', $closedCycle->id)
            ->where('status', 'DRAFT')
            ->first();
        if (!$draftTxn) {
            $date = $closedCycle->start_date ? $closedCycle->start_date->format('Y-m-d') : now()->subYear()->format('Y-m-d');
            $draftTxn = OperationalTransaction::create([
                'tenant_id' => $tenantId,
                'project_id' => $project->id,
                'crop_cycle_id' => $closedCycle->id,
                'type' => 'EXPENSE',
                'status' => 'DRAFT',
                'transaction_date' => $date,
                'amount' => 0.50,
                'classification' => 'SHARED',
                'created_by' => null,
            ]);
        }

        $this->setStoredValue($tenantId, 'closed_crop_cycle_id', $closedCycle->id);
        $this->setStoredValue($tenantId, 'draft_in_closed_cycle_transaction_id', $draftTxn->id);
        return [$closedCycle, $draftTxn];
    }

    private function ensureProject(string $tenantId, string $cropCycleId): Project
    {
        $project = Project::where('tenant_id', $tenantId)
            ->where('crop_cycle_id', $cropCycleId)
            ->where('name', 'E2E Project')
            ->first();
        if ($project) {
            return $project;
        }

        $party = Party::where('tenant_id', $tenantId)->where('name', 'E2E Party')->first();
        if (!$party) {
            $party = Party::create([
                'tenant_id' => $tenantId,
                'name' => 'E2E Party',
                'party_types' => ['HARI'],
            ]);
        }

        return Project::create([
            'tenant_id' => $tenantId,
            'name' => 'E2E Project',
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycleId,
            'status' => 'ACTIVE',
        ]);
    }

    private function ensureDraftTransaction(string $tenantId, string $cropCycleId, string $projectId): OperationalTransaction
    {
        $storedId = $this->getStoredValue($tenantId, 'draft_transaction_id');
        if ($storedId) {
            $txn = OperationalTransaction::where('id', $storedId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'DRAFT')
                ->first();
            if ($txn) {
                return $txn;
            }
        }

        $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
        $date = $cycle->start_date ? $cycle->start_date->format('Y-m-d') : now()->format('Y-m-d');

        $txn = OperationalTransaction::create([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'crop_cycle_id' => $cropCycleId,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => $date,
            'amount' => 1.00,
            'classification' => 'SHARED',
            'created_by' => null,
        ]);
        return $txn;
    }

    private function ensurePostedTransaction(string $tenantId, string $cropCycleId, string $projectId): array
    {
        $idempotencyKey = 'e2e_seed_posted_1';
        $existingPg = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
        if ($existingPg) {
            $txn = OperationalTransaction::where('id', $existingPg->source_id)
                ->where('tenant_id', $tenantId)
                ->where('status', 'POSTED')
                ->first();
            if ($txn) {
                return [$txn, $existingPg];
            }
        }

        $draftTxn = $this->createDraftForPosting($tenantId, $cropCycleId, $projectId, 'e2e_seed_posted');
        $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
        $postingDate = $cycle->start_date ? $cycle->start_date->format('Y-m-d') : now()->format('Y-m-d');

        $pg = $this->postingService->postOperationalTransaction(
            $draftTxn->id,
            $tenantId,
            $postingDate,
            $idempotencyKey
        );
        $draftTxn->refresh();
        return [$draftTxn, $pg];
    }

    private function ensureReversalReadyTransaction(string $tenantId, string $cropCycleId, string $projectId): array
    {
        $storedTxnId = $this->getStoredValue($tenantId, 'reversal_transaction_id');
        if ($storedTxnId) {
            $txn = OperationalTransaction::where('id', $storedTxnId)->where('tenant_id', $tenantId)->where('status', 'POSTED')->first();
            if ($txn && $txn->posting_group_id) {
                $reversalExists = PostingGroup::where('reversal_of_posting_group_id', $txn->posting_group_id)->exists();
                if (!$reversalExists) {
                    return [$txn, $txn->postingGroup];
                }
            }
        }

        $idempotencyKey = 'e2e_seed_reversal_1';
        $existingPg = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
        if ($existingPg) {
            $reversalExists = PostingGroup::where('reversal_of_posting_group_id', $existingPg->id)->exists();
            if (!$reversalExists) {
                $txn = OperationalTransaction::where('id', $existingPg->source_id)->where('tenant_id', $tenantId)->first();
                if ($txn) {
                    $this->setStoredValue($tenantId, 'reversal_transaction_id', $txn->id);
                    $this->setStoredValue($tenantId, 'reversal_posting_group_id', $existingPg->id);
                    return [$txn, $existingPg];
                }
            }
        }

        $draftTxn = $this->createDraftForPosting($tenantId, $cropCycleId, $projectId, 'e2e_seed_reversal');
        $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
        $postingDate = $cycle->start_date ? $cycle->start_date->format('Y-m-d') : now()->format('Y-m-d');

        $pg = $this->postingService->postOperationalTransaction(
            $draftTxn->id,
            $tenantId,
            $postingDate,
            $idempotencyKey
        );
        $draftTxn->refresh();
        $this->setStoredValue($tenantId, 'reversal_transaction_id', $draftTxn->id);
        $this->setStoredValue($tenantId, 'reversal_posting_group_id', $pg->id);
        return [$draftTxn, $pg];
    }

    private function createDraftForPosting(string $tenantId, string $cropCycleId, string $projectId, string $label): OperationalTransaction
    {
        $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
        $date = $cycle->start_date ? $cycle->start_date->format('Y-m-d') : now()->format('Y-m-d');

        return OperationalTransaction::create([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'crop_cycle_id' => $cropCycleId,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => $date,
            'amount' => 2.00,
            'classification' => 'SHARED',
            'created_by' => null,
        ]);
    }

    private function getStoredValue(string $tenantId, string $key): ?string
    {
        if (!Schema::hasTable('e2e_seed_state')) {
            return null;
        }
        $row = DB::table('e2e_seed_state')->where('tenant_id', $tenantId)->where('key', $key)->first();
        return $row ? $row->value : null;
    }

    private function setStoredValue(string $tenantId, string $key, string $value): void
    {
        if (!Schema::hasTable('e2e_seed_state')) {
            return;
        }
        $now = now();
        DB::table('e2e_seed_state')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => $value, 'created_at' => $now, 'updated_at' => $now]
        );
    }

    private function persistSeedState(string $tenantId, array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->setStoredValue($tenantId, $key, (string) $value);
        }
    }
}
