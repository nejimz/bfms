<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityModule;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    private const LIMIT = 50;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', Rule::enum(ActivityModule::class)],
        ], [
            'module.required' => 'Choose a module.',
            'module.enum' => 'Choose a module.',
        ]);

        $module = ActivityModule::from($data['module']);
        $this->authorizeModule($module);

        $logs = ActivityLog::query()
            ->where('module', $module)
            ->latest()
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'data' => $logs->map(fn (ActivityLog $log): array => [
                'id' => (string) $log->id,
                'type' => 'activity-logs',
                'attributes' => [
                    'actor_name' => $log->actor_name,
                    'action' => $log->action?->value,
                    'record_name' => $log->record_name,
                    'changes' => array_values($log->changes ?? []),
                    'created_at' => $log->created_at?->toISOString(),
                ],
            ])->values(),
        ]);
    }

    private function authorizeModule(ActivityModule $module): void
    {
        match ($module) {
            ActivityModule::Company => $this->authorize('update', Organization::current()),
            ActivityModule::Business => $this->authorize('viewAny', Company::class),
            ActivityModule::Classification => $this->authorize('viewAny', Classification::class),
            ActivityModule::Category => $this->authorize('viewAny', Category::class),
            ActivityModule::Location => $this->authorize('viewAny', Location::class),
            ActivityModule::User => $this->authorize('viewAny', User::class),
        };
    }
}
