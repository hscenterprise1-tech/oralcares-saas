<?php

namespace App\Console\Commands;

use App\Services\Staff\StaffIdentityLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports each clinic's staff identity: staff records, which have a login,
 * practitioners, branch access, and anything that needs a person to resolve.
 * With `--link` it also links staff to logins where the evidence is
 * unambiguous (see `StaffIdentityLinker`). It never grants roles.
 */
class StaffAuditCommand extends Command
{
    protected $signature = 'clinic:staff-audit
        {--clinic=* : Only these clinic ids}
        {--link : Link staff to logins where the evidence is unambiguous (default is report only)}
        {--details : List every unresolved staff record}';

    protected $description = 'Report clinic staff identity and, with --link, link staff records to their logins';

    public function handle(StaffIdentityLinker $linker): int
    {
        $rows = [];
        $unresolved = [];
        $linkedTotal = 0;

        $clinics = DB::table('clinics')
            ->when($this->option('clinic'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->orderBy('id');

        $clinics->chunkById(500, function ($chunk) use ($linker, &$rows, &$unresolved, &$linkedTotal) {
            foreach ($chunk as $clinic) {
                $linked = $this->option('link') ? $linker->link($clinic->id) : 0;
                $linkedTotal += $linked;
                $report = $linker->report($clinic->id);

                $rows[] = [
                    $clinic->id,
                    $report['employees'],
                    $report['linked'],
                    $report['unlinked'],
                    $report['practitioners'],
                    $report['membership_branch_rows'],
                    $report['members_without_branch_access'],
                    $report['invalid_staff_branch_rows'],
                    $report['unmigrated_availability_rows'],
                    $this->option('link') ? $linked : count($report['candidates']),
                    count($report['unresolved']),
                ];

                foreach ($report['unresolved'] as $item) {
                    $unresolved[] = [$clinic->id, $item['employee_id'], $item['reason']];
                }
            }
        });

        $this->table([
            'Clinic', 'Staff', 'Linked', 'Unlinked', 'Practitioners', 'Branch access rows',
            'Members w/o branch', 'Invalid staff-branch rows', 'Unmigrated availability',
            $this->option('link') ? 'Linked now' : 'Can link', 'Unresolved',
        ], $rows);

        $orphans = DB::table('clinic_employee_branches')
            ->whereNotExists(fn ($query) => $query->from('clinic_employees')->whereColumn('clinic_employees.id', 'clinic_employee_branches.clinic_employee_id'))
            ->count();

        if ($orphans > 0) {
            $this->warn("{$orphans} staff-branch row(s) point at staff records that no longer exist. They grant nothing and were left as they are.");
        }

        if ($unresolved !== [] && $this->option('details')) {
            $this->table(['Clinic', 'Staff record', 'Why it was not linked'], $unresolved);
        }

        $this->line($this->option('link')
            ? "Linked {$linkedTotal} staff record(s). Roles were not changed."
            : 'Report only. Nothing changed. Re-run with --link to link the "Can link" records.');

        return self::SUCCESS;
    }
}
