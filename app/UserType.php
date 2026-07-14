<?php

namespace App;

enum UserType: string
{
    case MasterAdmin = 'master_admin';
    case CompanyUser = 'company_user';
    case ClinicUser = 'clinic_user';
}
