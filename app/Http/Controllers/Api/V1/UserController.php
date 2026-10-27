<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\ActivityLogger;
use App\Enums\ActivityAction;
use App\Enums\ActivityModule;
use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()->with('companies')->orderBy('name')->orderBy('id')->get();

        return response()->json([
            'data' => $users->map(fn (User $user): array => $this->userResource($user))->values(),
        ]);
    }

    public function store(StoreUserRequest $request, ActivityLogger $activity): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'is_active' => true,
            ]);

            $this->syncCompanies($user, $data['company_ids'] ?? []);

            return $user;
        });

        $activity->record($request->user(), ActivityModule::User, ActivityAction::Added, $user->name);

        return response()->json(['data' => $this->userResource($user)], 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json(['data' => $this->userResource($user)]);
    }

    public function update(UpdateUserRequest $request, User $user, ActivityLogger $activity): JsonResponse
    {
        $data = $request->validated();
        $companyIds = $this->assignedCompanyIds($user);

        DB::transaction(function () use ($data, $user): void {
            $attributes = [
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
            ];

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = $data['is_active'];
            }

            if (filled($data['password'] ?? null)) {
                $attributes['password'] = $data['password'];
            }

            $user->update($attributes);
            $this->syncCompanies($user, $data['company_ids'] ?? []);
        });

        if ($user->wasChanged() || $companyIds !== $this->assignedCompanyIds($user)) {
            $activity->record($request->user(), ActivityModule::User, ActivityAction::Changed, $user->name);
        }

        return response()->json(['data' => $this->userResource($user->refresh())]);
    }

    public function destroy(Request $request, User $user, ActivityLogger $activity): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($request->user()?->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot deactivate your own account.',
            ]);
        }

        if (! $user->leavesAnActiveAdmin($user->role, false)) {
            throw ValidationException::withMessages([
                'user' => 'Keep at least one active admin.',
            ]);
        }

        $user->update(['is_active' => false]);
        $activity->record($request->user(), ActivityModule::User, ActivityAction::Changed, $user->name);

        return response()->json(['data' => $this->userResource($user)]);
    }

    /**
     * @return list<int>
     */
    private function assignedCompanyIds(User $user): array
    {
        return $user->companies()
            ->orderBy('companies.id')
            ->pluck('companies.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int|string>  $companyIds
     */
    private function syncCompanies(User $user, array $companyIds): void
    {
        if ($user->role !== UserRole::Staff) {
            $user->companies()->detach();

            return;
        }

        $user->companies()->sync(collect($companyIds)->mapWithKeys(
            fn (int|string $companyId): array => [(int) $companyId => ['role' => CompanyRole::Staff->value]],
        )->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function userResource(User $user): array
    {
        $user->loadMissing('companies');

        return [
            'id' => (string) $user->id,
            'type' => 'users',
            'attributes' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'is_active' => $user->is_active,
                'companies' => $user->companies
                    ->sortBy('name')
                    ->values()
                    ->map(fn (Company $company): array => [
                        'id' => (string) $company->id,
                        'name' => $company->name,
                    ]),
            ],
        ];
    }
}
