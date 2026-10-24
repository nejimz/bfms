<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ChartAccountEntryLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'chart_account_entry_id', 'position', 'name', 'quantity', 'amount_cents'])]
class ChartAccountEntryLine extends Model
{
    /** @use HasFactory<ChartAccountEntryLineFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'position' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ChartAccountEntry::class, 'chart_account_entry_id');
    }

    public function quantityDecimal(): string
    {
        $quantity = rtrim(rtrim((string) $this->quantity, '0'), '.');

        return $quantity === '' ? '0' : $quantity;
    }
}
