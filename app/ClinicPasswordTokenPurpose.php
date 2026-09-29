<?php

namespace App;

enum ClinicPasswordTokenPurpose: string
{
    /** The primary owner's first password, sent when Company provisions the clinic. */
    case Setup = 'setup';

    /** A forgotten password, requested from the clinic login page. */
    case Reset = 'reset';

    /**
     * How long a link of this kind stays usable. A setup link is sent before
     * the owner is expecting it and may sit in an inbox for a while; a reset
     * link is one the person just asked for.
     */
    public function lifetimeInMinutes(): int
    {
        return match ($this) {
            self::Setup => 48 * 60,
            self::Reset => 60,
        };
    }
}
