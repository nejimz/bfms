<?php

namespace App\Http\Requests;

use App\Models\Inventory;

class UpdateInventoryRequest extends StoreInventoryRequest
{
    public function authorize(): bool
    {
        $inventory = $this->route('inventory');

        return $inventory instanceof Inventory && $this->user()->can('update', $inventory);
    }
}
