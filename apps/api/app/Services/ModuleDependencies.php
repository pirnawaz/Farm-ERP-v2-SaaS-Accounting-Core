<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Support\Collection;

class ModuleDependencies
{
    /** @var array<string, list<string>> */
    protected array $dependencies;

    public function __construct()
    {
        $this->dependencies = config('modules.dependencies', []);
    }

    /**
     * Get the set of module keys that must be enabled (transitive) when enabling the given module.
     *
     * @return array<string> Unique list of dependency keys
     */
    public function resolveDependencies(string $moduleKey): array
    {
        $resolved = [];
        $this->collectDependencies($moduleKey, $resolved);
        return array_values(array_unique($resolved));
    }

    private function collectDependencies(string $moduleKey, array &$resolved): void
    {
        $deps = $this->dependencies[$moduleKey] ?? [];
        foreach ($deps as $dep) {
            if (!in_array($dep, $resolved, true)) {
                $resolved[] = $dep;
                $this->collectDependencies($dep, $resolved);
            }
        }
    }

    /**
     * Get enabled module keys for a tenant (core modules + tenant_modules ENABLED).
     *
     * @return array<string>
     */
    public function getEnabledModulesForTenant(string $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return [];
        }

        $modules = Module::all();
        $pivots = TenantModule::where('tenant_id', $tenantId)
            ->where('status', 'ENABLED')
            ->get()
            ->keyBy('module_id');

        $enabled = [];
        foreach ($modules as $m) {
            if ($m->is_core || ($pivots->get($m->id) !== null)) {
                $enabled[] = $m->key;
            }
        }
        return $enabled;
    }

    /**
     * Get the effective enabled set for a tenant: base enabled (core + persisted ENABLED)
     * plus the transitive closure of hard dependencies. Ensures e.g. Land is effectively
     * enabled when Projects & Crop Cycles (core) requires it, even if Land has no row yet.
     *
     * @return array<string>
     */
    public function getEffectiveEnabledModulesForTenant(string $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return [];
        }

        $modules = Module::all();
        $pivots = TenantModule::where('tenant_id', $tenantId)
            ->where('status', 'ENABLED')
            ->get()
            ->keyBy('module_id');

        $baseEnabled = [];
        foreach ($modules as $m) {
            if ($m->is_core || ($pivots->get($m->id) !== null)) {
                $baseEnabled[] = $m->key;
            }
        }

        $effective = $baseEnabled;
        foreach ($baseEnabled as $key) {
            foreach ($this->resolveDependencies($key) as $dep) {
                if (!in_array($dep, $effective, true)) {
                    $effective[] = $dep;
                }
            }
        }
        return array_values(array_unique($effective));
    }

    /**
     * Find enabled modules that depend (directly or transitively) on the given module.
     * If we disable $moduleKey, these modules would break.
     *
     * @param  array<string>  $enabledKeys  Set of currently enabled module keys
     * @return array<string> List of enabled module keys that require $moduleKey
     */
    public function findDependents(string $moduleKey, array $enabledKeys): array
    {
        $blockers = [];
        foreach ($enabledKeys as $candidate) {
            if ($candidate === $moduleKey) {
                continue;
            }
            $deps = $this->resolveDependencies($candidate);
            if (in_array($moduleKey, $deps, true)) {
                $blockers[] = $candidate;
            }
        }
        return $blockers;
    }

    /**
     * Get tier for a module key (CORE | CORE_ADJUNCT | OPTIONAL).
     */
    public function getTier(string $moduleKey): string
    {
        $tiers = config('modules.tiers', []);
        return $tiers[$moduleKey] ?? 'OPTIONAL';
    }

    /**
     * Get direct dependency keys for a module (no transitive).
     *
     * @return array<string>
     */
    public function getDirectDependencies(string $moduleKey): array
    {
        return $this->dependencies[$moduleKey] ?? [];
    }
}
