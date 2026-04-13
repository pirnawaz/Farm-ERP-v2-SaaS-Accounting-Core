<?php

namespace App\Console\Commands;

use App\Services\Dev\DemoTenantSeedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DemoSeedTenantCommand extends Command
{
    protected $signature = 'demo:seed-tenant
                            {--tenant-name=Terrava Demo Farm : Display name for the demo tenant}
                            {--tenant-slug=terrava-demo : Unique slug (subdomain / lookup)}
                            {--reset-passwords : Reset demo user passwords to the default}
                            {--fresh-demo-data : Reserved for future use (non-destructive; currently no-op)}
                            {--with-platform-admin : Also run platform:create-admin with bundled demo credentials}';

    protected $description = 'System verification dataset: idempotent demo tenant, modules, identities, domain postings, settlement pack, and coverage matrices';

    public function handle(DemoTenantSeedService $seedService): int
    {
        $tenantName = (string) $this->option('tenant-name');
        $tenantSlug = (string) $this->option('tenant-slug');
        $resetPasswords = (bool) $this->option('reset-passwords');
        $withPlatform = (bool) $this->option('with-platform-admin');

        if ($this->option('fresh-demo-data')) {
            $this->warn('Option --fresh-demo-data is reserved; no destructive reset was performed.');
        }

        if ($withPlatform) {
            $code = Artisan::call('platform:create-admin', [
                'email' => 'pirnawaz_ali@hotmail.com',
                'password' => 'Nawaz@1580',
                '--name' => 'Pir Nawaz Ali',
            ]);
            if ($code !== 0) {
                $this->error(Artisan::output());

                return self::FAILURE;
            }
            $this->line(trim(Artisan::output()));
        }

        try {
            $summary = $seedService->seed([
                'tenant_name' => $tenantName,
                'tenant_slug' => $tenantSlug,
                'reset_passwords' => $resetPasswords,
            ]);
        } catch (\Throwable $e) {
            $this->error('Demo seed failed: ' . $e->getMessage());
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== Terrava demo seed — credentials ===');
        $this->line('Platform admin: pirnawaz_ali@hotmail.com / Nawaz@1580');
        $this->line('Demo tenant: ' . $tenantName . ' (slug: ' . ($summary['tenant_slug'] ?? $tenantSlug) . ')');
        $this->line('Tenant users (Demo@12345): demo.admin@ | demo.accountant@ | demo.operator@ terrava.local');
        $this->newLine();

        $this->info('=== Counts ===');
        $this->line('projects: ' . ($summary['projects_count'] ?? 0));
        $this->line('crop cycles: ' . ($summary['crop_cycles_count'] ?? 0));
        $this->line('parties: ' . ($summary['parties_count'] ?? 0));
        if (!empty($summary['posted_by_source_type'])) {
            $this->line('posted by source_type:');
            foreach ($summary['posted_by_source_type'] as $src => $cnt) {
                $this->line('  ' . $src . ': ' . $cnt);
            }
        }
        if (!empty($summary['draft_counts'])) {
            $this->line('draft documents:');
            foreach ($summary['draft_counts'] as $k => $cnt) {
                $this->line('  ' . $k . ': ' . $cnt);
            }
        }

        if (!empty($summary['module_matrix'])) {
            $this->newLine();
            $this->info('=== MODULE COVERAGE MATRIX ===');
            foreach ($summary['module_matrix'] as $row) {
                $this->line(sprintf('  %-28s %s', $row['module'], $row['status']));
            }
        }

        if (!empty($summary['report_matrix'])) {
            $this->newLine();
            $this->info('=== REPORT COVERAGE MATRIX ===');
            foreach ($summary['report_matrix'] as $row) {
                $this->line(sprintf('  %-36s %s', $row['report'], $row['status']));
            }
        }

        if (!empty($summary['role_journey'])) {
            $this->newLine();
            $this->info('=== ROLE JOURNEY (API evidence) ===');
            foreach ($summary['role_journey'] as $row) {
                $this->line(sprintf('  %-18s %s', $row['role'], $row['note']));
            }
        }

        if (!empty($summary['known_gaps'])) {
            $this->newLine();
            $this->warn('=== KNOWN GAPS ===');
            foreach ($summary['known_gaps'] as $g) {
                $this->line('  - ' . $g);
            }
        }

        return self::SUCCESS;
    }
}
