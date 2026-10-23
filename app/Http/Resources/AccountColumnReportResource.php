<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class AccountColumnReportResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'account-column-reports';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'accounts' => $this->accounts->map(fn (Account $account): array => [
                'id' => (string) $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])->values()->all(),
        ];
    }
}
