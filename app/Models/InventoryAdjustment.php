<?php

namespace App\Models;

use Database\Factories\InventoryAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'date', 'quantity', 'note'])]
class InventoryAdjustment extends Model
{
    /** @use HasFactory<InventoryAdjustmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quantity' => 'decimal:3',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function quantityLabel(): string
    {
        return self::label((string) $this->quantity);
    }

    public static function label(string $quantity): string
    {
        $negative = str_starts_with($quantity, '-');
        $absolute = ltrim($quantity, '-');

        if (str_contains($absolute, '.')) {
            $absolute = rtrim(rtrim($absolute, '0'), '.');
        }

        if ($absolute === '') {
            $absolute = '0';
        }

        return $negative && $absolute !== '0' ? '-'.$absolute : $absolute;
    }
}
