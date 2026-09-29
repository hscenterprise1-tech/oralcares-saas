<?php

namespace App;

enum TaskType: string
{
    case Call = 'call';
    case Email = 'email';
    case Demo = 'demo';
    case FollowUp = 'follow_up';
    case ToDo = 'to_do';
}
