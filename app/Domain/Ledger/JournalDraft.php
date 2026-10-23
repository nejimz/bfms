<?php

namespace App\Domain\Ledger;

use App\Enums\JournalStatus;
use App\Models\Journal;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

class JournalDraft
{
    /**
     * @param  array{date: string, reference?: string|null, memo?: string|null, lines: list<array{account_id: int|string, description?: string|null, debit: string, credit: string}>}  $data
     */
    public function create(User $user, array $data): Journal
    {
        return DB::transaction(function () use ($user, $data) {
            $company = app(CurrentCompany::class)->company();

            $journal = Journal::query()->create([
                'company_id' => $company->id,
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'memo' => $data['memo'] ?? null,
                'currency' => $company->currency,
                'status' => JournalStatus::Draft,
                'created_by' => $user->id,
            ]);

            $this->replaceLines($journal, $data['lines']);

            return $journal->load('lines');
        });
    }

    /**
     * @param  array{date: string, reference?: string|null, memo?: string|null, lines: list<array{account_id: int|string, description?: string|null, debit: string, credit: string}>}  $data
     */
    public function update(Journal $journal, array $data): Journal
    {
        if ($journal->isPosted()) {
            throw new JournalAlreadyPostedException('Posted journals cannot be edited.');
        }

        return DB::transaction(function () use ($journal, $data) {
            $journal->update([
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'memo' => $data['memo'] ?? null,
            ]);

            $this->replaceLines($journal, $data['lines']);

            return $journal->load('lines');
        });
    }

    /**
     * @param  list<array{account_id: int|string, description?: string|null, debit: string, credit: string}>  $lines
     */
    private function replaceLines(Journal $journal, array $lines): void
    {
        $journal->lines()->delete();

        foreach ($lines as $line) {
            $journal->lines()->create([
                'company_id' => $journal->company_id,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'debit_cents' => Money::fromDecimal((string) $line['debit'])->minor,
                'credit_cents' => Money::fromDecimal((string) $line['credit'])->minor,
            ]);
        }
    }
}
