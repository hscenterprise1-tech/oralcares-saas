<?php

namespace App;

/**
 * A clinic provisioning run: queued until a worker picks it up, running
 * while stages execute, then succeeded or failed. A failed run is retried
 * from its last completed stage.
 */
enum ProvisioningRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
