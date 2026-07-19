<?php

namespace App;

enum ProvisioningState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
