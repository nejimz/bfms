<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class JournalResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'journals';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'date' => $this->date->toDateString(),
            'reference' => $this->reference,
            'memo' => $this->memo,
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
            'lines' => JournalLineResource::class,
        ];
    }
}
