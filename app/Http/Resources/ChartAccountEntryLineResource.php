<?php

namespace App\Http\Resources;

use App\Domain\Ledger\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class ChartAccountEntryLineResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'chart-account-entry-lines';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'item_id' => $this->item_id === null ? null : (string) $this->item_id,
            'name' => $this->name,
            'quantity' => $this->quantityDecimal(),
            'amount' => Money::ofMinor((int) $this->amount_cents)->toDecimal(),
        ];
    }
}
