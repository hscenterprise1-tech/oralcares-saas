<?php

namespace App;

enum OrganizationScale: string
{
    case SingleClinic = 'Single Clinic';
    case MultiClinic = 'Multi Clinic';
    case MultiCity = 'Multi City';
    case Franchise = 'Franchise';
    case HospitalChain = 'Hospital Chain';
}
