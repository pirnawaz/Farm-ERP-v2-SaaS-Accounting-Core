<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformCreateAdminCommand extends Command
{
    protected $signature = 'platform:create-admin
                            {email : Platform admin email}
                            {password : Password (min 8 chars)}
                            {--name= : Display name (defaults to email prefix)}';

    protected $description = 'Create the first platform admin user (tenant_id null, role platform_admin)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        $name = $this->option('name') ?? Str::before($email, '@');

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        $existing = User::whereNull('tenant_id')->where('email', $email)->first();
        if ($existing) {
            $this->error("A platform admin with email {$email} already exists.");
            return self::FAILURE;
        }

        User::create([
            'tenant_id' => null,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $this->info("Platform admin created: {$email}");
        return self::SUCCESS;
    }
}
