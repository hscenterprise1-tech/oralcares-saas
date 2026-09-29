<?php

namespace App;

enum CustomerChurnRisk: string
{
    case Low = 'Low';
    case Medium = 'Medium';
    case High = 'High';
}
