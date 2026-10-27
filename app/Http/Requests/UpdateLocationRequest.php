<?php

namespace App\Http\Requests;

use App\Models\Location;

class UpdateLocationRequest extends StoreLocationRequest
{
    public function authorize(): bool
    {
        $location = $this->route('location');

        return $location instanceof Location && $this->user()->can('update', $location);
    }
}
