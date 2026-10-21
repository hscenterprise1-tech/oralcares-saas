<?php

namespace App\Services\Numbering;

use App\ClinicDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Services\Core\NumberingEngine;
use App\Support\ClinicClock;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The only issuer of human-facing clinic document numbers.
 *
 * Numbers come from a locked sequence per scope (`NumberingEngine`): a branch
 * for financial documents, the clinic's organisation for clinical ones (see
 * `config/clinic_numbering.php`). They restart each financial year and carry
 * it: INV-2627-00001. Call inside the transaction that saves the document, so
 * the sequence row stays locked until the document exists.
 */
class ClinicDocumentNumbers
{
    public function __construct(protected NumberingEngine $engine) {}

    public function next(ClinicDocument $document, Clinic $clinic, ?Branch $branch = null, ?CarbonInterface $at = null): string
    {
        if ($document->perBranch() && ! $branch) {
            throw new InvalidArgumentException("A {$document->value} number belongs to a branch.");
        }

        if ($branch && $branch->clinic_id !== $clinic->id) {
            throw new InvalidArgumentException('The branch does not belong to this clinic.');
        }

        $organization = Organization::query()->withoutGlobalScopes()->find($clinic->organization_id)
            ?? throw new InvalidArgumentException('The clinic has no organisation to number documents for.');

        $at ??= ClinicClock::now();
        $config = $document->config();

        $sequence = $this->engine->issue(
            $document->value,
            $organization,
            $document->perBranch() ? $branch : null,
            ['prefix' => $config['prefix'], 'padding' => $config['padding'], 'reset_frequency' => 'financial_yearly'],
            $at,
        );

        return strtr((string) config('clinic_numbering.pattern'), [
            '{prefix}' => (string) ($sequence->prefix ?? $config['prefix']),
            '{fy}' => $this->financialYearCode($at),
            '{number}' => str_pad((string) $sequence->current_number, max((int) $sequence->padding, 1), '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * The financial year as a label, e.g. "2026-27".
     */
    public function financialYearLabel(?CarbonInterface $at = null): string
    {
        $start = NumberingEngine::financialYearStart($at ?? ClinicClock::now());

        return $start->year.'-'.substr((string) ($start->year + 1), -2);
    }

    /**
     * The financial year as it appears inside a number, e.g. "2627".
     */
    private function financialYearCode(CarbonInterface $at): string
    {
        $start = NumberingEngine::financialYearStart($at);

        return substr((string) $start->year, -2).substr((string) ($start->year + 1), -2);
    }
}
