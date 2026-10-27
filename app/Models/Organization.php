<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'logo_path', 'icon_path', 'banner_path'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            ['name' => 'BFMS'],
        );
    }

    public function deleteFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    public function logoUrl(): ?string
    {
        return $this->storedUrl($this->logo_path);
    }

    public function iconUrl(): ?string
    {
        return $this->storedUrl($this->icon_path);
    }

    public function bannerUrl(): ?string
    {
        return $this->storedUrl($this->banner_path);
    }

    /**
     * A root-relative URL so the login page and Vite dev server load the file
     * from the same origin as the API.
     */
    private function storedUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return '/storage/'.$path;
    }
}
