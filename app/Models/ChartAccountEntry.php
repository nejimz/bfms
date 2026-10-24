<?php

namespace App\Models;

use App\Domain\Ledger\Money;
use App\Enums\ChartAccountEntryStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ChartAccountEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function lines(): HasMany
    {
        return $this->hasMany(ChartAccountEntryLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Replace the item lines and set the entry amount to their sum.
     *
     * @param  list<array{name: string, quantity: string, amount: string}>  $lines
     */
    public function replaceLines(array $lines): void
    {
        $this->lines()->delete();

        $total = Money::zero();

        foreach (array_values($lines) as $position => $line) {
            $amount = Money::fromDecimal($line['amount']);
            $total = $total->plus($amount);

            $this->lines()->create([
                'company_id' => $this->company_id,
                'position' => $position,
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'amount_cents' => $amount->minor,
            ]);
        }

        $this->forceFill(['amount_cents' => $total->minor])->save();
        $this->unsetRelation('lines');
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
