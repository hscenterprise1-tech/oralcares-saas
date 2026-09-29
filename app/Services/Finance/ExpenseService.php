<?php

namespace App\Services\Finance;

use App\ExpenseStatus;
use App\Models\CompanyExpense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Moving an expense through its life.
 *
 * The order lives in `ExpenseStatus` and nowhere else, so there is no path that
 * reaches Paid without passing an approval. Submitting no longer approves: the
 * old `expensesStore` wrote `approved` in the same request that created the
 * row, behind a read permission.
 *
 * What this deliberately does not do: post to any ledger, compute tax, or
 * reconcile against a bank. None of that exists in this system and inventing it
 * here would be inventing accounting.
 */
class ExpenseService
{
    /**
     * Record a claim. It is submitted, not approved.
     *
     * @param  array{description: string, category: string, amount: numeric, expense_date: string, notes?: ?string}  $data
     */
    public function submit(array $data, ?User $actor = null): CompanyExpense
    {
        return CompanyExpense::create([
            'expense_number' => 'EXP-'.Str::upper(Str::random(6)),
            'description' => $data['description'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'notes' => $data['notes'] ?? null,
            'submitted_by' => $actor?->id,
            'submitted_at' => now(),
            'status' => ExpenseStatus::Submitted,
        ]);
    }

    /**
     * Move an expense along, recording who did it.
     *
     * @param  array{payment_reference?: ?string}  $context
     */
    public function transition(CompanyExpense $expense, ExpenseStatus $target, ?User $actor = null, array $context = []): CompanyExpense
    {
        if (! $expense->status->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "A {$expense->status->label()} expense cannot become {$target->label()}."
            );
        }

        /*
         * Separation of duties, enforced on the data rather than only in the
         * gate: the person who claimed an expense may not approve their own.
         */
        if ($target === ExpenseStatus::Approved
            && $actor !== null
            && $expense->submitted_by !== null
            && (int) $expense->submitted_by === (int) $actor->id) {
            throw new InvalidArgumentException('An expense cannot be approved by the person who submitted it.');
        }

        $stamp = match ($target) {
            ExpenseStatus::Approved => ['approved_at' => now(), 'approved_by' => $actor?->id],
            ExpenseStatus::Paid => [
                'paid_at' => now(),
                'paid_by' => $actor?->id,
                'payment_reference' => $context['payment_reference'] ?? null,
            ],
            default => [],
        };

        return DB::transaction(function () use ($expense, $target, $stamp) {
            $expense->update(['status' => $target] + $stamp);

            return $expense->fresh();
        });
    }
}
