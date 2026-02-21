<?php

namespace App\Domains\Platform\Modules;

use RuntimeException;

class ModuleDependencyService
{
    /** @var array<string, list<string>> */
    private array $dependencies;

    /** @var array<string, list<string>> */
    private array $dependents;

    public function __construct()
    {
        $this->dependencies = config('modules.dependencies', []);
        $this->dependents = $this->buildDependentsGraph();
    }

    /**
     * Build reverse graph: for each module key, list of keys that depend on it.
     *
     * @return array<string, list<string>>
     */
    private function buildDependentsGraph(): array
    {
        $dependents = [];
        foreach ($this->dependencies as $moduleKey => $deps) {
            foreach ($deps as $dep) {
                if (!isset($dependents[$dep])) {
                    $dependents[$dep] = [];
                }
                $dependents[$dep][] = $moduleKey;
            }
        }
        return $dependents;
    }

    /**
     * Get all required dependencies (transitive). Does NOT include $moduleKey itself.
     *
     * @return array<string>
     */
    public function getTransitiveDependencies(string $moduleKey): array
    {
        $visited = [];
        $result = [];
        $this->collectDependencies($moduleKey, $visited, $result);
        return array_values(array_unique($result));
    }

    private function collectDependencies(string $moduleKey, array &$visited, array &$result): void
    {
        if (isset($visited[$moduleKey])) {
            throw new RuntimeException("Cycle detected in module dependencies involving: {$moduleKey}");
        }
        $visited[$moduleKey] = true;

        $deps = $this->dependencies[$moduleKey] ?? [];
        foreach ($deps as $dep) {
            $result[] = $dep;
            $this->collectDependencies($dep, $visited, $result);
        }

        unset($visited[$moduleKey]);
    }

    /**
     * Get all modules that (directly or indirectly) depend on $moduleKey.
     *
     * @return array<string>
     */
    public function getTransitiveDependents(string $moduleKey): array
    {
        $visited = [];
        $result = [];
        $this->collectDependents($moduleKey, $visited, $result);
        return array_values(array_unique($result));
    }

    private function collectDependents(string $moduleKey, array &$visited, array &$result): void
    {
        if (isset($visited[$moduleKey])) {
            throw new RuntimeException("Cycle detected in module dependents involving: {$moduleKey}");
        }
        $visited[$moduleKey] = true;

        $deps = $this->dependents[$moduleKey] ?? [];
        foreach ($deps as $dependent) {
            $result[] = $dependent;
            $this->collectDependents($dependent, $visited, $result);
        }

        unset($visited[$moduleKey]);
    }

    /**
     * Get direct dependencies only (from config).
     *
     * @return array<string>
     */
    public function getDirectDependencies(string $moduleKey): array
    {
        return $this->dependencies[$moduleKey] ?? [];
    }
}
