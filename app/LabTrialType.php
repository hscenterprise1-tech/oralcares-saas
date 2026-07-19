<?php

namespace App;

enum LabTrialType: string
{
    case WaxTrial = 'wax_trial';
    case MetalTrial = 'metal_trial';
    case BisqueTrial = 'bisque_trial';
    case FinalFitting = 'final_fitting';
}
