<?php

namespace App\Http\Requests;

use App\Models\Category;

class UpdateCategoryRequest extends StoreCategoryRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category && $this->user()->can('update', $category);
    }
}
