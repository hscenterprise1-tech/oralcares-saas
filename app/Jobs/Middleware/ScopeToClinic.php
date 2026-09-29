<?php

namespace App\Jobs\Middleware;

use App\ClinicStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Services\ClinicContext;
use Closure;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Runs a `RunsForClinic` job inside its clinic's tenant context.
 *
 * It re-resolves the clinic from the ids the job carries and refuses to run
 * when they do not line up: the organization id must match the clinic, and
 * the branch must belong to the clinic. A job for a clinic that is no longer
 * active is dropped rather than run, the same rule a web request follows.
 */
class ScopeToClinic
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! isset($job->tenantClinicId)) {
            throw new LogicException(class_basename($job).' uses RunsForClinic but was dispatched without forClinic().');
        }

        $clinic = Clinic::query()->find($job->tenantClinicId);

        if (! $clinic || (int) $clinic->organization_id !== (int) $job->tenantOrganizationId) {
            throw new LogicException(class_basename($job).' carries a clinic that does not exist or does not belong to its organization.');
        }

        if ($clinic->status !== ClinicStatus::Active) {
            Log::info('Skipped a clinic job because the clinic is not active.', ['job' => class_basename($job), 'clinic_id' => $clinic->id]);

            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return null;
        }

        $branch = null;

        if ($job->tenantBranchId !== null) {
            $branch = Branch::query()->where('clinic_id', $clinic->id)->find($job->tenantBranchId);

            if (! $branch) {
                throw new LogicException(class_basename($job).' carries a branch that does not belong to its clinic.');
            }
        }

        return app(ClinicContext::class)->runForClinic($clinic, $branch, fn () => $next($job));
    }
}
