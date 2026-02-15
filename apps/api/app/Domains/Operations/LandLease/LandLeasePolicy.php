<?php

namespace App\Domains\Operations\LandLease;

class LandLeasePolicy
{
    public function viewAny($user): bool
    {
        return $user->role === 'tenant_admin';
    }

    public function view($user, LandLease $landLease): bool
    {
        return $user->role === 'tenant_admin' && $user->tenant_id === $landLease->tenant_id;
    }

    public function create($user): bool
    {
        return $user->role === 'tenant_admin';
    }

    public function update($user, LandLease $landLease): bool
    {
        return $user->role === 'tenant_admin' && $user->tenant_id === $landLease->tenant_id;
    }

    public function delete($user, LandLease $landLease): bool
    {
        return $user->role === 'tenant_admin' && $user->tenant_id === $landLease->tenant_id;
    }
}
