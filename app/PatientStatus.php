<?php

namespace App;

enum PatientStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deceased = 'deceased';

    /** Removed from the working directory by an owner-approved request; the record is kept. */
    case Archived = 'archived';

    /** A duplicate merged into another patient (see `patient_merges`); kept, never shown as a working record. */
    case Merged = 'merged';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Deceased => 'Deceased',
            self::Archived => 'Archived',
            self::Merged => 'Merged',
        };
    }

    /**
     * Statuses of patients that belong in the working directory and search.
     *
     * @return list<string>
     */
    public static function working(): array
    {
        return [self::Active->value, self::Inactive->value, self::Deceased->value];
    }
}
