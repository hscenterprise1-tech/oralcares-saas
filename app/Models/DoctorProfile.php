<?php

namespace App\Models;

/**
 * @deprecated Legacy name for the practitioner profile. Use `Practitioner`.
 * Kept only for the unrouted legacy doctor/encounter controllers; it adds
 * nothing to `Practitioner` and reads the same table.
 */
class DoctorProfile extends Practitioner
{
    //
}
