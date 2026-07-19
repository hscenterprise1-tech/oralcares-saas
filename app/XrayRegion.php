<?php

namespace App;

enum XrayRegion: string
{
    case SingleTooth = 'single_tooth';
    case MultipleTeeth = 'multiple_teeth';
    case FullArch = 'full_arch';
    case UpperJaw = 'upper_jaw';
    case LowerJaw = 'lower_jaw';
    case EntireMouth = 'entire_mouth';
}
