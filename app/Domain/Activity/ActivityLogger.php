<?php

namespace App\Domain\Activity;

use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogger
{
    public function record(?User $actor, ActivityModule $module, ActivityAction $action, string $recordName): ?ActivityLog
    {
        if (! $actor instanceof User) {
            return null;
        }

        return ActivityLog::query()->create([
            'user_id' => $actor->id,
            'actor_name' => $actor->name,
            'module' => $module,
            'action' => $action,
            'record_name' => $recordName,
        ]);
    }
}
