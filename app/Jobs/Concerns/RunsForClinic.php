<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\ScopeToClinic;
use App\Models\Branch;
use App\Models\Clinic;

/**
 * For every queued job that reads or writes one clinic's data.
 *
 * The job carries its tenant as plain ids (organization, clinic, optional
 * branch), set when it is dispatched. They survive serialization unchanged
 * and are the only source of the tenant when the job runs: never a session,
 * the dispatching user, or leftover state. `ScopeToClinic` re-checks them and
 * runs `handle()` inside `ClinicContext::runForClinic()`, so every
 * clinic-scoped query in the job is filtered to that clinic.
 *
 * Usage: `use RunsForClinic;` then call `$this->forClinic($clinic, $branch)`
 * in the constructor. A job that uses its own `middleware()` must include
 * `...$this->clinicMiddleware()`.
 */
trait RunsForClinic
{
    public int $tenantOrganizationId;

    public int $tenantClinicId;

    public ?int $tenantBranchId = null;

    protected function forClinic(Clinic $clinic, ?Branch $branch = null): void
    {
        $this->tenantOrganizationId = (int) $clinic->organization_id;
        $this->tenantClinicId = $clinic->id;
        $this->tenantBranchId = $branch?->id;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->clinicMiddleware();
    }

    /**
     * @return array<int, object>
     */
    protected function clinicMiddleware(): array
    {
        return [new ScopeToClinic];
    }
}
