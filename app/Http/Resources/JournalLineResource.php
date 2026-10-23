<?php

namespace App\Http\Resources;

use App\Domain\Ledger\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class JournalLineResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'journal-lines';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'account_id' => (string) $this->account_id,
            'description' => $this->description,
            'debit' => Money::ofMinor((int) $this->debit_cents)->toDecimal(),
            'credit' => Money::ofMinor((int) $this->credit_cents)->toDecimal(),
        ];
    }
}
