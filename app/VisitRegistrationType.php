<?php

namespace App;

enum VisitRegistrationType: string
{
    case New = 'new';
    case Repeat = 'repeat';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New Patient',
            self::Repeat => 'Repeat / Old Patient',
        };
    }
}
