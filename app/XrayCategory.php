<?php

namespace App;

enum XrayCategory: string
{
    case IOPA = 'iopa';
    case OPG = 'opg';
    case CBCT = 'cbct';
    case Bitewing = 'bitewing';
    case Occlusal = 'occlusal';
    case Cephalometric = 'cephalometric';
    case Extraoral = 'extraoral';
    case Custom = 'custom';
}
