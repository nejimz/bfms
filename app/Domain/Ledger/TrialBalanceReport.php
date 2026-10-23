<?php

namespace App\Domain\Ledger;

use App\Enums\JournalStatus;
use App\Models\JournalLine;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;

class TrialBalanceReport
{
    /**
     * @return array{data: list<array{account_id: string, code: string, name: string, type: string, debit: string, credit: string}>, meta: array{from: string, to: string, currency: string, debit_total: string, credit_total: string, difference: string}}
     */
    public function build(Carbon $from, Carbon $to): array
    {
        $company = app(CurrentCompany::class)->company();

        $rows = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.status', JournalStatus::Posted->value)
            ->whereDate('journals.date', '>=', $from->toDateString())
            ->whereDate('journals.date', '<=', $to->toDateString())
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->select('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->selectRaw('SUM(journal_lines.debit_cents) as debit_cents')
            ->selectRaw('SUM(journal_lines.credit_cents) as credit_cents')
            ->get();

        $debitTotal = Money::zero();
        $creditTotal = Money::zero();
        $data = [];

        foreach ($rows as $row) {
            $debit = Money::ofMinor((int) $row->debit_cents);
            $credit = Money::ofMinor((int) $row->credit_cents);
            $debitTotal = $debitTotal->plus($debit);
            $creditTotal = $creditTotal->plus($credit);

            $data[] = [
                'account_id' => (string) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type instanceof \BackedEnum ? $row->type->value : (string) $row->type,
                'debit' => $debit->toDecimal(),
                'credit' => $credit->toDecimal(),
            ];
        }

        return [
            'data' => $data,
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'currency' => $company->currency,
                'debit_total' => $debitTotal->toDecimal(),
                'credit_total' => $creditTotal->toDecimal(),
                'difference' => Money::ofMinor($debitTotal->minor - $creditTotal->minor)->toDecimal(),
            ],
        ];
    }
}
