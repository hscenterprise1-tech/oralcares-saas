<?php

namespace App;

enum ContactRole: string
{
    case Owner = 'Owner';
    case MedicalDirector = 'Medical Director';
    case Administrator = 'Administrator';
    case IT = 'IT';
    case Finance = 'Finance';
    case ReceptionManager = 'Reception Manager';
    case Other = 'Other';
}
