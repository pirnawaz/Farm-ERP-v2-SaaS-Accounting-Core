<?php

namespace App\Policies;

use App\Domains\Operations\LandLease\LandLeaseAccrual;

class LandLeaseAccrualPolicy
{
    public function viewAny($user): bool
    {
        return $user->role === 'tenant_admin';
    }

    public function view($user, LandLeaseAccrual $accrual): bool
    {
        return $user->role === 'tenant_admin' && $user->tenant_id === $accrual->tenant_id;
    }

    public function create($user): bool
    {
        return $user->role === 'tenant_admin';
    }

    public function update($user, LandLeaseAccrual $accrual): bool
    {
        if ($user->role !== 'tenant_admin' || $user->tenant_id !== $accrual->tenant_id) {
            return false;
        }
        return $accrual->status === LandLeaseAccrual::STATUS_DRAFT;
    }

    public function delete($user, LandLeaseAccrual $accrual): bool
    {
        if ($user->role !== 'tenant_admin' || $user->tenant_id !== $accrual->tenant_id) {
            return false;
        }
        return $accrual->status === LandLeaseAccrual::STATUS_DRAFT;
    }

    /**
     * Determine if the user can post the accrual (create accounting artifacts).
     */
    public function post($user, LandLeaseAccrual $accrual): bool
    {
        if (!$user || $user->role !== 'tenant_admin' || $user->tenant_id !== $accrual->tenant_id) {
            return false;
        }
        return $accrual->status === LandLeaseAccrual::STATUS_DRAFT;
    }

    /**
     * Determine if the user can reverse a posted accrual (create reversal posting group).
     */
    public function reverse($user, LandLeaseAccrual $accrual): bool
    {
        if (!$user || $user->role !== 'tenant_admin' || $user->tenant_id !== $accrual->tenant_id) {
            return false;
        }
        if ($accrual->status !== LandLeaseAccrual::STATUS_POSTED) {
            return false;
        }
        if ($accrual->reversal_posting_group_id !== null) {
            return false;
        }
        return true;
    }
}
