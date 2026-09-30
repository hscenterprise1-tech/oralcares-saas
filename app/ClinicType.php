<?php

namespace App;

enum ClinicType: string
{
    case DentalClinic = 'Dental Clinic';
    case DentalHospital = 'Dental Hospital';
    case GeneralHospital = 'General Hospital';
    case MultiSpecialityHospital = 'Multi-speciality Hospital';
    case DiagnosticCenter = 'Diagnostic Center';
    case IVF = 'IVF';
    case EyeHospital = 'Eye Hospital';
    case Physiotherapy = 'Physiotherapy';
    case Veterinary = 'Veterinary';
    case CustomHealthcare = 'Custom Healthcare';
}
