<?php

namespace App\Console\Commands;

use App\Models\Identity;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Backfill identities and tenant_memberships from existing users table.
 * Idempotent: skips emails that already have an Identity; updates identity_id on users when missing.
 */
class BackfillIdentitiesFromUsersCommand extends Command
{
    protected $signature = 'identities:backfill-from-users
                            {--dry-run : Do not write; only report }
                            {--report-conflicts : Report password hash conflicts to stderr }';

    protected $description = 'Backfill identities and tenant_memberships from existing users (idempotent)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $reportConflicts = (bool) $this->option('report-conflicts');

        if ($dryRun) {
            $this->warn('Dry run: no changes will be written.');
        }

        $users = User::orderBy('created_at')->orderBy('id')->get();
        $byEmail = [];
        foreach ($users as $user) {
            $email = $user->email ? strtolower(trim($user->email)) : '';
            if ($email === '') {
                continue;
            }
            if (!isset($byEmail[$email])) {
                $byEmail[$email] = [];
            }
            $byEmail[$email][] = $user;
        }

        $created = 0;
        $membershipsCreated = 0;
        $usersLinked = 0;
        $conflicts = [];

        foreach ($byEmail as $email => $userList) {
            $first = $userList[0];
            $passwordHash = $first->password;

            foreach ($userList as $u) {
                if ($u->password !== null && $u->password !== $passwordHash) {
                    $conflicts[] = [
                        'email' => $email,
                        'user_id' => $u->id,
                        'tenant_id' => $u->tenant_id,
                    ];
                    if ($reportConflicts) {
                        $this->error("Password hash conflict: {$email} (user {$u->id}, tenant {$u->tenant_id}). Using first encountered hash.");
                    }
                }
            }

            $identity = Identity::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if (!$identity) {
                if (!$dryRun) {
                    $identity = Identity::create([
                        'email' => $email,
                        'password_hash' => $passwordHash ?? '',
                        'is_enabled' => $first->is_enabled ?? true,
                        'is_platform_admin' => $first->tenant_id === null && $first->role === 'platform_admin',
                        'token_version' => 1,
                    ]);
                    $created++;
                } else {
                    $this->line("Would create Identity for: {$email}");
                    $created++;
                }
            }

            if ($identity) {
                foreach ($userList as $user) {
                    if (!$dryRun && $user->identity_id !== $identity->id) {
                        $user->update(['identity_id' => $identity->id]);
                        $usersLinked++;
                    } elseif ($dryRun && !$user->identity_id) {
                        $usersLinked++;
                    }

                    $tenantId = $user->tenant_id;
                    if ($tenantId === null) {
                        continue;
                    }

                    $exists = TenantMembership::where('identity_id', $identity->id)
                        ->where('tenant_id', $tenantId)->exists();
                    if (!$exists) {
                        if (!$dryRun) {
                            TenantMembership::create([
                                'identity_id' => $identity->id,
                                'tenant_id' => $tenantId,
                                'role' => $user->role,
                                'is_enabled' => $user->is_enabled ?? true,
                            ]);
                            $membershipsCreated++;
                        } else {
                            $this->line("Would create membership: {$email} -> tenant {$tenantId} as {$user->role}");
                            $membershipsCreated++;
                        }
                    }
                }
            }
        }

        $this->info("Identities created: {$created}");
        $this->info("Tenant memberships created: {$membershipsCreated}");
        $this->info("Users linked (identity_id set): {$usersLinked}");
        if (count($conflicts) > 0) {
            $this->warn('Password hash conflicts: ' . count($conflicts));
            if ($reportConflicts) {
                foreach ($conflicts as $c) {
                    $this->line("  - {$c['email']} user_id={$c['user_id']} tenant_id={$c['tenant_id']}");
                }
            }
        }

        return 0;
    }
}
