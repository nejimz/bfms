<?php

namespace App\Domain\Ledger;

use App\Enums\JournalStatus;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class JournalPoster
{
    public function post(Journal $journal, User $user): Journal
    {
        return DB::transaction(function () use ($journal, $user) {
            /** @var Journal $journal */
            $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            if ($journal->isPosted()) {
                throw new JournalAlreadyPostedException('This journal is already posted.');
            }

            $journal->load('company', 'lines.account');
            $this->assertPostable($journal);

            $journal->forceFill([
                'status' => JournalStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $user->id,
            ])->save();

            return $journal->load('lines');
        });
    }

    private function assertPostable(Journal $journal): void
    {
        $lines = $journal->lines;

        if ($lines->count() < 2) {
            throw new JournalPostingException('A journal needs at least two lines before it can post.');
        }

        if ($journal->currency !== $journal->company->currency) {
            throw new JournalPostingException('The journal currency must match the company currency.');
        }

        $debit = 0;
        $credit = 0;

        foreach ($lines as $line) {
            if ($line->account === null || $line->account->company_id !== $journal->company_id) {
                throw new JournalPostingException('Every line must use an account from this company.');
            }

            if ($line->debit_cents < 0 || $line->credit_cents < 0) {
                throw new JournalPostingException('Amounts cannot be negative.');
            }

            if ($line->debit_cents > 0 && $line->credit_cents > 0) {
                throw new JournalPostingException('A line cannot have both a debit and a credit.');
            }

            if ($line->debit_cents === 0 && $line->credit_cents === 0) {
                throw new JournalPostingException('Each line needs a debit or a credit.');
            }

            $debit = $this->add($debit, $line->debit_cents);
            $credit = $this->add($credit, $line->credit_cents);
        }

        if ($debit !== $credit) {
            throw new JournalPostingException('Debits must equal credits before this journal can post.');
        }
    }

    private function add(int $total, int $amount): int
    {
        $sum = $total + $amount;

        if (! is_int($sum)) {
            throw new JournalPostingException('That amount is too large.');
        }

        return $sum;
    }
}
