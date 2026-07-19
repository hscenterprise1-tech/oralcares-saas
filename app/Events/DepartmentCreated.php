<?php

namespace App\Events;

use App\Models\ClinicDepartment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DepartmentCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicDepartment $department
    ) {}
}
