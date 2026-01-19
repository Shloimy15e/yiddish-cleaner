<?php

namespace App\Policies;

use App\Models\TrainingVersion;
use App\Models\User;

class TrainingVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TrainingVersion $trainingVersion): bool
    {
        return $trainingVersion->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TrainingVersion $trainingVersion): bool
    {
        return $trainingVersion->user_id === $user->id;
    }

    public function delete(User $user, TrainingVersion $trainingVersion): bool
    {
        return $trainingVersion->user_id === $user->id;
    }
}
