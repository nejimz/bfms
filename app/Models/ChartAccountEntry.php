<?php

namespace App\Models;

use App\Enums\ChartAccountEntryStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ChartAccountEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'account_id', 'date', 'particulars', 'amount_cents', 'currency', 'status', 'posted_at', 'created_by', 'posted_by'])]
class ChartAccountEntry extends Model
{
    /** @use HasFactory<ChartAccountEntryFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => ChartAccountEntryStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->status === ChartAccountEntryStatus::Posted;
    }
}
