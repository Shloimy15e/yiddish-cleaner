<?php

use App\Models\ProcessingRun;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Processing run channel - user can only listen to their own runs
Broadcast::channel('runs.{runId}', function ($user, $runId) {
    $run = ProcessingRun::find($runId);

    return $run && $run->user_id === $user->id;
});
