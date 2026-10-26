<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'logo_path'])]
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

    public function deleteLogoFile(): void
    {
        if ($this->logo_path === null) {
            return;
        }

        Storage::disk('public')->delete($this->logo_path);
    }

    /**
     * A root-relative URL so the browser tab, login page, and Vite dev server
     * load the file from the same origin as the API.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null || $this->logo_path === '') {
            return null;
        }

        return '/storage/'.$this->logo_path;
    }
}
