<?php

use App\ClinicStatus;
use App\Contracts\MalwareScannerInterface;
use App\Exceptions\TenantContextMissingException;
use App\Jobs\Concerns\RunsForClinic;
use App\Models\AttachedDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Models\Patient;
use App\Services\ClinicContext;
use App\Services\Security\Malware\FakeMalwareScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A job that counts "its" clinic's patients: the kind of read that returned
 * every clinic's rows when run in a queue worker with no tenant context.
 */
class CountClinicPatientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsForClinic, SerializesModels;

    public static ?int $seen = null;

    public function __construct(Clinic $clinic, ?Branch $branch = null)
    {
        $this->forClinic($clinic, $branch);
    }

    public function handle(): void
    {
        self::$seen = Patient::count();
    }
}

/** The same read, written without a tenant, the mistake the guard exists for. */
class UnscopedPatientReadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        Patient::count();
    }
}

/** A deliberately platform-wide read, declared as such. */
class PlatformPatientTotalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?int $seen = null;

    public function handle(ClinicContext $context): void
    {
        self::$seen = $context->withoutTenancy(fn () => Patient::count());
    }
}

function clinicWithPatients(int $count, array $attributes = []): Clinic
{
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id] + $attributes);

    foreach (range(1, $count) as $i) {
        Patient::withoutGlobalScopes()->create([
            'clinic_id' => $clinic->id,
            'organization_id' => $organization->id,
            'uhid' => 'UH-'.Str::upper(Str::random(8)),
            'first_name' => 'P',
            'last_name' => (string) $i,
            'name' => "P {$i}",
            'phone' => '9'.random_int(100000000, 999999999),
            'gender' => 'male',
        ]);
    }

    return $clinic;
}

beforeEach(function () {
    CountClinicPatientsJob::$seen = null;
    PlatformPatientTotalJob::$seen = null;
});

it('runs a clinic job against its own clinic only', function () {
    $clinicA = clinicWithPatients(2);
    clinicWithPatients(5);

    CountClinicPatientsJob::dispatch($clinicA);

    expect(CountClinicPatientsJob::$seen)->toBe(2);
});

it('carries its tenant as plain ids that survive serialization', function () {
    $clinic = clinicWithPatients(1);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);

    $restored = unserialize(serialize(new CountClinicPatientsJob($clinic, $branch)));

    expect($restored->tenantClinicId)->toBe($clinic->id)
        ->and($restored->tenantOrganizationId)->toBe($clinic->organization_id)
        ->and($restored->tenantBranchId)->toBe($branch->id);
});

it('ignores whoever dispatched it: the tenant comes from the job, not the session', function () {
    $clinicA = clinicWithPatients(3);
    $clinicB = clinicWithPatients(4);

    // A "current" context for clinic B, as a web request would have.
    app(ClinicContext::class)->runForClinic($clinicB, null, function () use ($clinicA) {
        CountClinicPatientsJob::dispatch($clinicA);

        // And B's context is back once the job is done.
        expect(app(ClinicContext::class)->clinic()->id)->not->toBeNull();
    });

    expect(CountClinicPatientsJob::$seen)->toBe(3);
});

it('refuses clinic data read in a job that has no tenant', function () {
    clinicWithPatients(2);

    expect(fn () => UnscopedPatientReadJob::dispatch())->toThrow(TenantContextMissingException::class);
});

it('allows a cross-clinic read only when the job declares it', function () {
    clinicWithPatients(2);
    clinicWithPatients(3);

    PlatformPatientTotalJob::dispatch();

    expect(PlatformPatientTotalJob::$seen)->toBe(5);
});

it('does not run a job for a clinic that is no longer active', function () {
    $clinic = clinicWithPatients(2, ['status' => ClinicStatus::Suspended]);

    CountClinicPatientsJob::dispatch($clinic);

    expect(CountClinicPatientsJob::$seen)->toBeNull();
});

it('refuses a job whose branch belongs to another clinic', function () {
    $clinicA = clinicWithPatients(1);
    $clinicB = clinicWithPatients(1);
    $foreignBranch = Branch::factory()->create(['clinic_id' => $clinicB->id, 'organization_id' => $clinicB->organization_id]);

    $job = new CountClinicPatientsJob($clinicA);
    $job->tenantBranchId = $foreignBranch->id;

    expect(fn () => dispatch($job))->toThrow(LogicException::class);
    expect(CountClinicPatientsJob::$seen)->toBeNull();
});

it('refuses to run a clinic-data command without naming the clinics', function () {
    $this->artisan('documents:rescan')->assertExitCode(1);
});

it('processes only the named clinic in a per-clinic command', function () {
    Storage::fake('private');
    app()->instance(MalwareScannerInterface::class, new FakeMalwareScanner);
    $clinicA = clinicWithPatients(1);
    $clinicB = clinicWithPatients(1);

    foreach ([$clinicA, $clinicB] as $clinic) {
        Storage::disk('private')->put("c{$clinic->id}/doc.pdf", 'fine');
        AttachedDocument::withoutGlobalScopes()->create([
            'clinic_id' => $clinic->id,
            'patient_id' => Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->value('id'),
            'file_name' => 'doc.pdf',
            'file_path' => "c{$clinic->id}/doc.pdf",
            'file_size_bytes' => 4,
            'document_type' => 'other',
        ]);
    }

    $this->artisan('documents:rescan', ['--clinic' => [$clinicA->id]])->assertExitCode(0);

    expect(AttachedDocument::withoutGlobalScopes()->where('clinic_id', $clinicA->id)->value('scan_status'))->toBe('clean')
        ->and(AttachedDocument::withoutGlobalScopes()->where('clinic_id', $clinicB->id)->value('scan_status'))->toBe('unscanned');
});

it('leaves artisan commands and fixtures unscoped outside a job', function () {
    clinicWithPatients(2);
    clinicWithPatients(3);

    expect(Patient::count())->toBe(5);
});
