<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'unit', 'description'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /**
     * Entry lines on every business. Items are shared, so this is not limited to the current company.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ChartAccountEntryLine::class)->withoutGlobalScope('company');
    }
}
