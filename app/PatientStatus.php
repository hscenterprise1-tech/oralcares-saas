<?php

namespace App;

enum PatientStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /** A duplicate merged into another patient (see `patient_merges`); kept, never shown as a working record. */
    case Merged = 'merged';

    /** Deleted by the owner (governed `patient.delete`); kept in the database as history, never shown as a working record. */
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Merged => 'Merged',
            self::Deleted => 'Deleted',
        };
    }

    /**
     * Statuses of patients that belong in the working directory and search.
     *
     * @return list<string>
     */
    public static function working(): array
    {
        return [self::Active->value, self::Inactive->value];
    }
}
