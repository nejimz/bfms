<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AccountColumnReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['company_id', 'name'])]
class AccountColumnReport extends Model
{
    /** @use HasFactory<AccountColumnReportFactory> */
    use BelongsToCompany, HasFactory;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_column_report_account')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * @param  list<int>  $accountIds
     */
    public function syncAccounts(array $accountIds): void
    {
        $sync = [];

        foreach (array_values($accountIds) as $position => $accountId) {
            $sync[$accountId] = ['position' => $position];
        }

        $this->accounts()->sync($sync);
        $this->unsetRelation('accounts');
    }
}
