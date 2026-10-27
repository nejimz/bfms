<?php

namespace App\Models;

use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'quantity', 'note'])]
class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function quantityLabel(): string
    {
        $quantity = rtrim(rtrim((string) $this->quantity, '0'), '.');

        return $quantity === '' ? '0' : $quantity;
    }

    public function recordName(): string
    {
        $this->loadMissing('item');

        return $this->item?->name ?? 'Item';
    }
}
