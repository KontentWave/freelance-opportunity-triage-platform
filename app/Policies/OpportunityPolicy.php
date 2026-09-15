<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;

final class OpportunityPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->workspace_id !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Opportunity $opportunity): bool
    {
        return $user->workspace_id !== null
            && $user->workspace_id === $opportunity->workspace_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->view($user, $opportunity);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Opportunity $opportunity): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Opportunity $opportunity): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Opportunity $opportunity): bool
    {
        return false;
    }
}
