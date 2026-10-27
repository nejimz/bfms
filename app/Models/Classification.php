<?php

namespace App\Models;

use Database\Factories\ClassificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'category_id', 'logo_path'])]
class Classification extends Model
{
    /** @use HasFactory<ClassificationFactory> */
    use HasFactory;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
        if ($this->logo_path === null || $this->logo_path === '') {
            return null;
        }

        return '/storage/'.$this->logo_path;
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
