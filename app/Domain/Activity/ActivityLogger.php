<?php

namespace App\Domain\Activity;

use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogger
{
    /**
     * @param  list<array{field: string, from: string, to: string}>  $changes
     */
    public function record(?User $actor, ActivityModule $module, ActivityAction $action, string $recordName, array $changes = []): ?ActivityLog
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
            'changes' => $changes === [] ? null : $changes,
        ]);
    }

    public function label(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? 'None' : $value;
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return list<array{field: string, from: string, to: string}>
     */
    public function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $to) {
            $from = $before[$field] ?? 'None';

            if ($from === $to) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'from' => $from,
                'to' => $to,
            ];
        }

        return $changes;
    }

    /**
     * @return list<array{field: string, from: string, to: string}>
     */
    public function picture(string $field, ?string $before, ?string $after): array
    {
        $before = $this->presentPath($before);
        $after = $this->presentPath($after);

        if ($before === $after) {
            return [];
        }

        return [[
            'field' => $field,
            'from' => $before === null ? 'None' : 'On file',
            'to' => match (true) {
                $after === null => 'None',
                $before === null => 'Added',
                default => 'Replaced',
            },
        ]];
    }

    private function presentPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return $path;
    }
}
