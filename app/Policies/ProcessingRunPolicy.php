<?php

namespace App\Policies;

use App\Models\ProcessingRun;
use App\Models\User;

class ProcessingRunPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProcessingRun $processingRun): bool
    {
        return $processingRun->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProcessingRun $processingRun): bool
    {
        return $processingRun->user_id === $user->id;
    }

    public function delete(User $user, ProcessingRun $processingRun): bool
    {
        return $processingRun->user_id === $user->id;
    }
}
