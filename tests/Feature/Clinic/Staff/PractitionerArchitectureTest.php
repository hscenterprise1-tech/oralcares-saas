<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\Practitioner;
use App\Models\PractitionerSchedule;
use App\Models\SecurityAuditLog;
use App\Services\Staff\PractitionerScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Clinical identity is the staff record (`clinic_employees.id`). The
 * practitioner profile is its one specialty-neutral extension, and the
 * practitioner schedule says when that person works at which branch.
 */
beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->otherClinic = Clinic::factory()->create();
    $this->otherBranch = Branch::factory()->create(['clinic_id' => $this->otherClinic->id]);
    $this->staff = fn (string $name, ?Clinic $clinic = null) => ClinicEmployee::create([
        'clinic_id' => ($clinic ?? $this->clinic)->id, 'name' => $name, 'email' => str($name)->slug().'@clinic.test', 'status' => 'active',
    ]);
});

// ─── Practitioner profile ───────────────────────────────────

it('takes the practitioner profile\'s clinic and organisation from its staff record', function () {
    $profile = Practitioner::create(['clinic_employee_id' => ($this->staff)('Dr. Rao')->id, 'specialty' => 'Dentist']);

    expect($profile->clinic_id)->toBe($this->clinic->id)
        ->and($profile->organization_id)->toBe($this->clinic->organization_id);
});

it('refuses a practitioner profile in a different clinic from its staff record', function () {
    $employee = ($this->staff)('Dr. Rao');

    expect(fn () => Practitioner::create(['clinic_employee_id' => $employee->id, 'clinic_id' => $this->otherClinic->id, 'organization_id' => $this->otherClinic->organization_id]))
        ->toThrow(LogicException::class, 'staff record\'s clinic');
});

it('allows one practitioner profile per staff record', function () {
    $employee = ($this->staff)('Dr. Rao');
    Practitioner::create(['clinic_employee_id' => $employee->id, 'specialty' => 'Dentist']);

    expect(fn () => Practitioner::create(['clinic_employee_id' => $employee->id, 'specialty' => 'Orthodontist']))
        ->toThrow(QueryException::class);
});

it('serves dentists and physiotherapists from the same profile model', function () {
    $dental = ClinicalSpecialty::factory()->create(['organization_id' => $this->clinic->organization_id, 'name' => 'Endodontics']);
    $physio = ClinicalSpecialty::factory()->create(['organization_id' => $this->clinic->organization_id, 'name' => 'Sports physiotherapy']);

    $dentist = Practitioner::create(['clinic_employee_id' => ($this->staff)('Dr. Dent')->id, 'specialty' => 'Dentist']);
    $physiotherapist = Practitioner::create(['clinic_employee_id' => ($this->staff)('Ms. Physio')->id, 'specialty' => 'Physiotherapist']);
    $dentist->specialties()->attach($dental);
    $physiotherapist->specialties()->attach($physio);

    expect(ClinicEmployee::activePractitioners($this->clinic->id))->toHaveCount(2)
        ->and($dentist->specialties()->pluck('name')->all())->toBe(['Endodontics'])
        ->and($physiotherapist->specialties()->pluck('name')->all())->toBe(['Sports physiotherapy']);
});

// ─── Practitioner schedule ──────────────────────────────────

it('keeps a schedule inside its staff member\'s clinic and branches', function () {
    $employee = ($this->staff)('Dr. Rao');

    $schedule = PractitionerSchedule::create(['clinic_employee_id' => $employee->id, 'branch_id' => $this->branch->id, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
    expect($schedule->clinic_id)->toBe($this->clinic->id);

    expect(fn () => PractitionerSchedule::create(['clinic_employee_id' => $employee->id, 'branch_id' => $this->otherBranch->id, 'day_of_week' => 1, 'start_time' => '14:00:00', 'end_time' => '18:00:00']))
        ->toThrow(LogicException::class);
});

it('reads one doctor\'s windows for one branch and day, within their effective dates', function () {
    $employee = ($this->staff)('Dr. Rao');
    $east = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $monday = CarbonImmutable::parse('2026-09-28');

    PractitionerSchedule::create(['clinic_employee_id' => $employee->id, 'branch_id' => $this->branch->id, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
    PractitionerSchedule::create(['clinic_employee_id' => $employee->id, 'branch_id' => $east->id, 'day_of_week' => 1, 'start_time' => '15:00:00', 'end_time' => '19:00:00']);
    PractitionerSchedule::create(['clinic_employee_id' => $employee->id, 'branch_id' => $this->branch->id, 'day_of_week' => 2, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
    PractitionerSchedule::create(['clinic_employee_id' => $employee->id, 'branch_id' => $this->branch->id, 'day_of_week' => 1, 'start_time' => '17:00:00', 'end_time' => '18:00:00', 'effective_until' => '2026-09-01']);

    $windows = app(PractitionerScheduleService::class)->windowsOn($this->clinic, [$employee->id], $monday, $this->branch);

    expect($windows->get($employee->id)->pluck('start_time')->all())->toBe(['09:00:00'])
        ->and(app(PractitionerScheduleService::class)->windowsOn($this->otherClinic, [$employee->id], $monday))->toBeEmpty();
});

// ─── Migrations ─────────────────────────────────────────────

it('copies legacy availability only where staff, branch and clinic agree, converting the day numbering', function () {
    $employee = ($this->staff)('Dr. Rao');
    $profile = Practitioner::create(['clinic_employee_id' => $employee->id]);
    $legacy = fn (int $branchId, int $day) => DB::table('doctor_availabilities')->insert([
        'organization_id' => $this->clinic->organization_id, 'branch_id' => $branchId, 'doctor_profile_id' => $profile->id,
        'day_of_week' => $day, 'start_time' => '09:00:00', 'end_time' => '13:00:00', 'is_available' => true,
    ]);
    $legacy($this->branch->id, 7);      // Sunday in the legacy 1..7 numbering
    $legacy($this->otherBranch->id, 1); // another clinic's branch: not copied

    $migration = require database_path('migrations/2026_09_29_120636_create_practitioner_schedules_table.php');
    $migration->down();
    $migration->up();

    $copied = PractitionerSchedule::withoutGlobalScopes()->get();
    expect($copied)->toHaveCount(1)
        ->and($copied->first()->branch_id)->toBe($this->branch->id)
        ->and($copied->first()->day_of_week)->toBe(0)
        ->and(DB::table('doctor_availabilities')->count())->toBe(2);
});

it('stops without changing data when a staff record has two practitioner profiles', function () {
    $migration = require database_path('migrations/2026_09_29_120634_harden_staff_identity_constraints.php');
    $migration->down();

    $employee = ($this->staff)('Dr. Rao');
    foreach (['Dentist', 'Orthodontist'] as $specialty) {
        DB::table('doctor_profiles')->insert(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'clinic_employee_id' => $employee->id, 'specialty' => $specialty, 'experience_years' => 0, 'is_active' => true]);
    }

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'more than one practitioner profile');
    expect(DB::table('doctor_profiles')->where('clinic_employee_id', $employee->id)->count())->toBe(2);
});

// ─── Audit ──────────────────────────────────────────────────

it('records staff, profile and schedule changes without personal details', function () {
    $employee = ($this->staff)('Dr. Private Name');
    $profile = Practitioner::create(['clinic_employee_id' => $employee->id, 'registration_number' => 'REG-SECRET-123']);
    $profile->update(['registration_number' => 'REG-SECRET-456', 'is_active' => false]);
    PractitionerSchedule::create(['clinic_employee_id' => $employee->id, 'branch_id' => $this->branch->id, 'day_of_week' => 3, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);
    $employee->update(['status' => 'inactive']);

    $events = SecurityAuditLog::pluck('event')->all();
    expect($events)->toContain('staff.employee.created', 'staff.practitioner_profile.created', 'staff.practitioner_profile.updated', 'staff.schedule.created', 'staff.employee.updated');

    $trail = SecurityAuditLog::where('event', 'like', 'staff.%')->get()->map(fn ($log) => json_encode($log->context))->implode(' ');
    expect($trail)->not->toContain('Private Name')
        ->not->toContain('REG-SECRET')
        ->not->toContain('@clinic.test');

    $statusChange = SecurityAuditLog::where('event', 'staff.employee.updated')->first();
    expect($statusChange->context['from']['status'])->toBe('active')
        ->and($statusChange->context['to']['status'])->toBe('inactive');
});
