<?php

namespace App\Http\Requests;

use App\Models\Classification;

class UpdateClassificationRequest extends StoreClassificationRequest
{
    public function authorize(): bool
    {
        $classification = $this->route('classification');

        return $classification instanceof Classification && $this->user()->can('update', $classification);
    }
}
