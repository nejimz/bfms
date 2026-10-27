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
use Illuminate\Validation\ValidationException;

#[Fillable(['company_id', 'account_id', 'date', 'particulars', 'reference', 'amount_cents', 'cash_cents', 'credit_cents', 'currency', 'status', 'posted_at', 'created_by', 'posted_by'])]
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
     * The stored name is the catalog name, so a name sent with the line is ignored.
     *
     * @param  list<array{item_id: int|string, quantity: string, amount: string}>  $lines
     */
    public function replaceLines(array $lines): void
    {
        $catalog = Item::query()
            ->whereIn('id', array_column($lines, 'item_id'))
            ->get()
            ->keyBy('id');

        $this->lines()->delete();

        $total = Money::zero();

        foreach (array_values($lines) as $position => $line) {
            $item = $catalog->get((int) $line['item_id']);

            if (! $item instanceof Item) {
                throw ValidationException::withMessages([
                    "lines.$position.item_id" => 'Choose an item.',
                ]);
            }

            $amount = Money::fromDecimal($line['amount']);
            $total = $total->plus($amount);

            $this->lines()->create([
                'company_id' => $this->company_id,
                'position' => $position,
                'item_id' => $item->id,
                'name' => $item->name,
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
