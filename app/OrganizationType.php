<?php

namespace App;

enum OrganizationType: string
{
    case DentalClinic = 'dental_clinic';
    case Hospital = 'hospital';
    case MultiSpecialityHospital = 'multi_speciality_hospital';
    case DiagnosticCenter = 'diagnostic_center';
    case MedicalCenter = 'medical_center';
    case HealthcareChain = 'healthcare_chain';
    case Other = 'other';
}
