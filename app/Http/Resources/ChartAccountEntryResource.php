<?php

namespace App\Http\Resources;

use App\Domain\Ledger\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class ChartAccountEntryResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'chart-account-entries';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'date' => $this->date->toDateString(),
            'account_id' => (string) $this->account_id,
            'particulars' => $this->particulars,
            'reference' => $this->reference,
            'amount' => Money::ofMinor((int) $this->amount_cents)->toDecimal(),
            'cash' => Money::ofMinor((int) $this->cash_cents)->toDecimal(),
            'credit' => Money::ofMinor((int) $this->credit_cents)->toDecimal(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'posted_at' => $this->posted_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toRelationships(Request $request): array
    {
        return [
            'account' => AccountResource::class,
            'lines' => ChartAccountEntryLineResource::class,
        ];
    }
}
