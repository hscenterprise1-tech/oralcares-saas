<?php

namespace App;

enum WorkflowActionType: string
{
    case Move = 'move';
    case Hold = 'hold';
    case Return = 'return';
    case Skip = 'skip';
    case Complete = 'complete';
    case Cancel = 'cancel';
    case Escalate = 'escalate';
}
