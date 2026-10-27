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
        $before = $this->userFields($activity, $user);
        $password = filled($data['password'] ?? null);

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

        $changes = $activity->changes($before, $this->userFields($activity, $user));

        if ($password) {
            $changes[] = [
                'field' => 'Password',
                'from' => 'Hidden',
                'to' => 'Updated',
            ];
        }

        if ($changes !== []) {
            $activity->record($request->user(), ActivityModule::User, ActivityAction::Changed, $user->name, $changes);
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

        $active = $user->is_active;
        $user->update(['is_active' => false]);

        if ($active) {
            $activity->record($request->user(), ActivityModule::User, ActivityAction::Changed, $user->name, [
                ['field' => 'Status', 'from' => 'Active', 'to' => 'Inactive'],
            ]);
        }

        return response()->json(['data' => $this->userResource($user)]);
    }

    /**
     * @return array<string, string>
     */
    private function userFields(ActivityLogger $activity, User $user): array
    {
        return [
            'Name' => $activity->label($user->name),
            'Email' => $activity->label($user->email),
            'Role' => $this->roleLabel($user->role),
            'Status' => $user->is_active ? 'Active' : 'Inactive',
            'Businesses' => $this->businessLabel($user),
        ];
    }

    private function roleLabel(mixed $role): string
    {
        if (! $role instanceof UserRole) {
            $role = UserRole::tryFrom(is_string($role) ? $role : '');
        }

        return match ($role) {
            UserRole::Admin => 'Admin',
            UserRole::Manager => 'Manager',
            UserRole::Staff => 'Staff',
            default => 'None',
        };
    }

    private function businessLabel(User $user): string
    {
        if ($user->role !== UserRole::Staff) {
            return 'All';
        }

        $names = $user->companies()->orderBy('companies.name')->pluck('companies.name');

        if ($names->isEmpty()) {
            return 'None';
        }

        return $names->join(', ');
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
