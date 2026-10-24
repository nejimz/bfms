<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('role')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function seesEveryCompany(): bool
    {
        return $this->role === UserRole::Admin || $this->role === UserRole::Manager;
    }

    /**
     * Businesses this person can open.
     *
     * @return Builder<Company>
     */
    public function accessibleCompanies(): Builder
    {
        $query = Company::query();

        if ($this->seesEveryCompany()) {
            return $query;
        }

        return $query->whereIn('companies.id', function ($subquery): void {
            $subquery->select('company_user.company_id')
                ->from('company_user')
                ->where('company_user.user_id', $this->id);
        });
    }

    public function leavesAnActiveAdmin(UserRole $role, bool $active): bool
    {
        if ($this->role !== UserRole::Admin || ! $this->is_active) {
            return true;
        }

        if ($role === UserRole::Admin && $active) {
            return true;
        }

        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->whereKeyNot($this->id)
            ->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }
}
