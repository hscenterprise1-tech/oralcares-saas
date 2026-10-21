<?php

namespace App;

/**
 * The clinic documents that carry a human-facing number. Prefix, padding and
 * series (branch or organisation) are configuration, in
 * `config/clinic_numbering.php`; this enum is only the list of kinds.
 */
enum ClinicDocument: string
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case Refund = 'refund';
    case Encounter = 'encounter';
    case Prescription = 'prescription';
    case ClinicalOrder = 'clinical_order';
    case Imaging = 'imaging';
    case LabOrder = 'lab_order';

    /**
     * @return array{prefix: string, padding: int, series: string}
     */
    public function config(): array
    {
        return config("clinic_numbering.documents.{$this->value}");
    }

    /** Whether each branch keeps its own series (financial documents). */
    public function perBranch(): bool
    {
        return $this->config()['series'] === 'branch';
    }
}
