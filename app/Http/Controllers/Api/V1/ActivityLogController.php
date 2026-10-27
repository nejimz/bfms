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
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', Rule::enum(ActivityModule::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ], [
            'module.required' => 'Choose a module.',
            'module.enum' => 'Choose a module.',
            'page.integer' => 'Choose a page.',
            'page.min' => 'Choose a page.',
        ]);

        $module = ActivityModule::from($data['module']);
        $this->authorizeModule($module);

        $logs = ActivityLog::query()
            ->where('module', $module)
            ->latest()
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

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
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
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
