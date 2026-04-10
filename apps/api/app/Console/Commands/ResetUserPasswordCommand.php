<?php

namespace App\Console\Commands;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Updates users.password (legacy tenant login) and identities.password_hash (unified login)
 * for every user row matching the email, plus the identity row if present.
 */
class ResetUserPasswordCommand extends Command
{
    protected $signature = 'user:reset-password
                            {email : User email (case-insensitive match)}
                            {password : New password (min 8 characters)}';

    protected $description = 'Reset password for a tenant user (all matching User rows + Identity)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->argument('password');

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $hash = Hash::make($password);

        $users = User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->get();
        foreach ($users as $user) {
            $user->update([
                'password' => $hash,
                'token_version' => $user->token_version + 1,
                'last_password_change_at' => now(),
                'must_change_password' => false,
            ]);
            $this->info("Updated user {$user->id} (tenant_id={$user->tenant_id}, role={$user->role})");
        }

        $identity = Identity::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
        if ($identity) {
            $identity->update([
                'password_hash' => $hash,
                'token_version' => $identity->token_version + 1,
            ]);
            $this->info("Updated identity {$identity->id}");
        }

        if ($users->isEmpty() && ! $identity) {
            $this->error("No user or identity found for email: {$email}");
            $this->comment('Check spelling, database connection (.env), and that the account exists.');

            return self::FAILURE;
        }

        $this->info('Password reset complete.');

        return self::SUCCESS;
    }
}
