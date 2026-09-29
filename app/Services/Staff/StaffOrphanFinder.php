<?php

namespace App\Services\Staff;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finds rows hanging off staff records that point at something that no
 * longer exists: the staff record itself, or the branch, role, department
 * or room it links to. These tables had no foreign keys, so deleting a staff
 * record left its placements, roles and invitations behind.
 *
 * A row is an orphan only when a referenced parent row is missing. Ids are
 * never reused, so such a row can only be recovered by restoring its parent
 * from a backup; it grants nothing in the meantime.
 */
class StaffOrphanFinder
{
    /**
     * Staff child table => [column => parent table].
     *
     * @var array<string, array<string, string>>
     */
    public const REFERENCES = [
        'clinic_employee_branches' => ['clinic_employee_id' => 'clinic_employees', 'branch_id' => 'branches'],
        'clinic_employee_roles' => ['clinic_employee_id' => 'clinic_employees', 'role_id' => 'spatie_roles'],
        'clinic_employee_departments' => ['clinic_employee_id' => 'clinic_employees', 'clinic_department_id' => 'clinic_departments'],
        'clinic_employee_rooms' => ['clinic_employee_id' => 'clinic_employees', 'clinic_room_id' => 'clinic_rooms'],
        'clinic_employee_invitations' => ['clinic_employee_id' => 'clinic_employees'],
    ];

    /**
     * Delete every orphaned row, writing each one (minus any invitation
     * token) to the security log first.
     *
     * @return int the number of rows removed
     */
    public function remove(): int
    {
        $removed = 0;

        foreach ($this->find()->groupBy('table') as $table => $rows) {
            $ids = $rows->pluck('id')->all();

            Log::channel(config('security.audit.log_channel', 'security'))->warning('Removed staff rows whose parent no longer exists', [
                'table' => $table,
                'rows' => DB::table($table)->whereIn('id', $ids)->get()->map(fn ($row) => collect((array) $row)->except('token')->all())->all(),
            ]);

            $removed += DB::table($table)->whereIn('id', $ids)->delete();
        }

        return $removed;
    }

    /**
     * @return Collection<int, array{table: string, id: int, employee_id: int, other: string|null, missing: list<string>, clinic_id: int|null, created_at: string|null}>
     */
    public function find(): Collection
    {
        $orphans = collect();

        foreach (self::REFERENCES as $table => $references) {
            $query = DB::table($table)->where(function ($query) use ($table, $references) {
                foreach ($references as $column => $parent) {
                    $query->orWhereNotExists(fn ($exists) => $exists->from($parent)->whereColumn("{$parent}.id", "{$table}.{$column}"));
                }
            });

            foreach ($query->orderBy('id')->get() as $row) {
                $missing = collect($references)
                    ->filter(fn (string $parent, string $column) => ! DB::table($parent)->where('id', $row->{$column})->exists())
                    ->keys()
                    ->all();

                $other = collect($references)->keys()->reject(fn ($column) => $column === 'clinic_employee_id')->first();

                $orphans->push([
                    'table' => $table,
                    'id' => (int) $row->id,
                    'employee_id' => (int) $row->clinic_employee_id,
                    'other' => $other ? "{$other}=".$row->{$other} : null,
                    'missing' => $missing,
                    'clinic_id' => $row->clinic_id ?? ($other === 'branch_id' ? DB::table('branches')->where('id', $row->branch_id)->value('clinic_id') : null),
                    'created_at' => $row->created_at ?? null,
                ]);
            }
        }

        return $orphans;
    }
}
