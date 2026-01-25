<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine if the user can view any models.
     */
    public function viewAny($user): bool
    {
        return $user->role === 'tenant_admin';
    }

    /**
     * Determine if the user can view the model.
     */
    public function view($user, User $model): bool
    {
        return $user->role === 'tenant_admin' && $user->tenant_id === $model->tenant_id;
    }

    /**
     * Determine if the user can create models.
     */
    public function create($user): bool
    {
        return $user->role === 'tenant_admin';
    }

    /**
     * Determine if the user can update the model.
     */
    public function update($user, User $model): bool
    {
        return $user->role === 'tenant_admin' && $user->tenant_id === $model->tenant_id;
    }

    /**
     * Determine if the user can delete the model.
     */
    public function delete($user, User $model): bool
    {
        return $user->role === 'tenant_admin' && $user->tenant_id === $model->tenant_id;
    }
}
