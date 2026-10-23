<?php

namespace App\Models\Concerns;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $companyId = app(CurrentCompany::class)->id();

            if ($companyId !== null) {
                $query->where($query->getModel()->getTable().'.company_id', $companyId);
            }
        });

        static::creating(function (Model $model) {
            if (! $model->getAttribute('company_id') && $companyId = app(CurrentCompany::class)->id()) {
                $model->company_id = $companyId;
            }
        });
    }
}
