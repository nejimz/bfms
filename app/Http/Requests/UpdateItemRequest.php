<?php

namespace App\Http\Requests;

use App\Models\Item;

class UpdateItemRequest extends StoreItemRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item instanceof Item && $this->user()->can('update', $item);
    }
}
