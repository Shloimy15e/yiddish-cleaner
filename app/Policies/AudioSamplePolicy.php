<?php

namespace App\Policies;

use App\Models\AudioSample;
use App\Models\User;

class AudioSamplePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AudioSample $audioSample): bool
    {
        return $audioSample->processingRun?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AudioSample $audioSample): bool
    {
        return $audioSample->processingRun?->user_id === $user->id;
    }

    public function delete(User $user, AudioSample $audioSample): bool
    {
        return $audioSample->processingRun?->user_id === $user->id;
    }
}
