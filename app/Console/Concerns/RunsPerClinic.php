<?php

namespace App\Console\Concerns;

use App\ClinicStatus;
use App\Models\Clinic;
use App\Services\ClinicContext;
use InvalidArgumentException;

/**
 * For artisan commands that process clinic data.
 *
 * Such a command must be told which clinic(s) to touch, either
 * `--clinic=ID` (repeatable) or an explicit `--all-clinics`. Each clinic is
 * then processed inside `ClinicContext::runForClinic()`, so a query that
 * forgets its `where clinic_id` is still filtered to the clinic being worked
 * on and cannot reach into another. A command that is run with neither option
 * does nothing.
 *
 * Add to the signature: `{--clinic=* : Clinic id(s)} {--all-clinics : Every active clinic}`
 */
trait RunsPerClinic
{
    /**
     * @param  callable(Clinic): void  $callback
     */
    protected function forEachSelectedClinic(callable $callback): int
    {
        $ids = array_filter(array_map('intval', (array) $this->option('clinic')));
        $all = (bool) $this->option('all-clinics');

        if ($ids === [] && ! $all) {
            throw new InvalidArgumentException('Name the clinic(s) with --clinic=ID, or pass --all-clinics explicitly.');
        }

        $clinics = Clinic::query()
            ->where('status', ClinicStatus::Active)
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        $context = app(ClinicContext::class);

        foreach ($clinics as $clinic) {
            $context->runForClinic($clinic, null, fn () => $callback($clinic));
        }

        return $clinics->count();
    }
}
