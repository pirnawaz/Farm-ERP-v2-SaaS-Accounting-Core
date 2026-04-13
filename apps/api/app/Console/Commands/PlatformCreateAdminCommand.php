<?php

namespace App\Console\Commands;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformCreateAdminCommand extends Command
{
    protected $signature = 'platform:create-admin
                            {email : Platform admin email}
                            {password : Password (min 8 chars)}
                            {--name= : Display name (defaults to email prefix)}';

    protected $description = 'Create or update the platform admin user (tenant_id null, role platform_admin) and matching Identity for unified login';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->argument('password');
        $name = $this->option('name') ?? Str::before($email, '@');

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        $passwordHash = Hash::make($password);

        return DB::transaction(function () use ($email, $passwordHash, $name) {
            $identity = Identity::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();

            if ($identity) {
                $identity->update([
                    'password_hash' => $passwordHash,
                    'is_enabled' => true,
                    'is_platform_admin' => true,
                ]);
            } else {
                $identity = Identity::create([
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'is_enabled' => true,
                    'is_platform_admin' => true,
                    'token_version' => 1,
                ]);
            }

            $user = User::whereNull('tenant_id')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();

            if ($user) {
                if ($user->role !== 'platform_admin') {
                    $this->error('A user with this email exists but is not a platform admin.');
                    return self::FAILURE;
                }
                $user->update([
                    'identity_id' => $identity->id,
                    'name' => $name,
                    'password' => $passwordHash,
                    'is_enabled' => true,
                ]);
                $this->info("Platform admin updated: {$email}");
                return self::SUCCESS;
            }

            User::create([
                'identity_id' => $identity->id,
                'tenant_id' => null,
                'name' => $name,
                'email' => $email,
                'password' => $passwordHash,
                'role' => 'platform_admin',
                'is_enabled' => true,
            ]);

            $this->info("Platform admin created: {$email}");
            return self::SUCCESS;
        });
    }
}
