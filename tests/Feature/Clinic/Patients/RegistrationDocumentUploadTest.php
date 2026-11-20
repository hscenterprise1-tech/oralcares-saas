<?php

use App\Contracts\MalwareScannerInterface;
use App\Models\AttachedDocument;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\SecurityAuditLog;
use App\Services\Security\Malware\FakeMalwareScanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ReceptionFixture;

/*
 * Documents chosen on the registration form go through the one secure intake
 * (quarantine → malware scan → private disk), as a Patient 360 upload does.
 * A document that is refused or cannot be stored is reported with a safe
 * reason, never dropped silently; the patient is registered either way.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    Storage::fake('private');
    Storage::fake('quarantine');
    $this->scanner = new FakeMalwareScanner;
    app()->instance(MalwareScannerInterface::class, $this->scanner);
    $this->register = fn (array $files) => $this->actingAs($this->f->owner)->withSession($this->f->session)->post('/clinic/patients', [
        'surface' => 'full_page', 'first_name' => 'Suman', 'last_name' => 'Devi', 'gender' => 'female', 'age' => 45, 'phone' => '9876505678',
        'documents' => $files, 'document_types' => array_fill(0, count($files), 'id_proof'),
    ]);
    $this->patient = fn () => Patient::query()->where('phone', '9876505678')->sole();
});

test('a clean document is scanned, stored privately, filed, audited and on the timeline', function () {
    ($this->register)([UploadedFile::fake()->createWithContent('aadhaar.pdf', '%PDF-1.4 clean')])->assertSessionHasNoErrors()->assertSessionMissing('warning');

    $document = AttachedDocument::withoutGlobalScopes()->sole();
    expect($document->patient_id)->toBe(($this->patient)()->id)
        ->and($document->clinic_id)->toBe($this->f->clinic->id)
        ->and($document->document_type)->toBe('id_proof')
        ->and($document->scan_status)->toBe(AttachedDocument::SCAN_CLEAN)
        ->and($this->scanner->scanned)->toHaveCount(1)
        ->and(SecurityAuditLog::where('event', 'document.uploaded')->exists())->toBeTrue()
        ->and(PatientTimelineEvent::where('event_type', 'document_uploaded')->exists())->toBeTrue();
    Storage::disk('private')->assertExists($document->file_path);
});

test('an infected document is refused and named, and the patient is still registered', function () {
    $response = ($this->register)([UploadedFile::fake()->createWithContent('scan.pdf', FakeMalwareScanner::TEST_MARKER)]);

    $response->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'scan.pdf was not saved') && str_contains($warning, 'malware'));
    expect(($this->patient)())->not->toBeNull()
        ->and(AttachedDocument::withoutGlobalScopes()->count())->toBe(0)
        ->and(SecurityAuditLog::where('event', 'document.malware_blocked')->exists())->toBeTrue();
});

test('a document the scanner cannot check is not saved, and the reason is given', function () {
    $this->scanner->failWith('unavailable');

    ($this->register)([UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 clean')])
        ->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'report.pdf was not saved') && str_contains($warning, 'could not be checked'));
    expect(AttachedDocument::withoutGlobalScopes()->count())->toBe(0);
});

test('a storage failure is reported without the file path or the error, and other documents still save', function () {
    // The private disk refuses writes (a full or unreachable store).
    $broken = Mockery::mock(Storage::disk('private'))->makePartial();
    $broken->shouldReceive('writeStream')->once()->andThrow(new RuntimeException('disk /var/secret/path is full'));
    $broken->shouldReceive('writeStream')->andReturn(true);
    Storage::set('private', $broken);

    $response = ($this->register)([
        UploadedFile::fake()->createWithContent('first.pdf', '%PDF-1.4 a'),
        UploadedFile::fake()->createWithContent('second.pdf', '%PDF-1.4 b'),
    ]);

    $response->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'first.pdf was not saved') && ! str_contains($warning, '/var/secret') && ! str_contains($warning, 'full'));
    expect(AttachedDocument::withoutGlobalScopes()->pluck('file_name')->all())->toBe(['second.pdf'])
        ->and(($this->patient)())->not->toBeNull();
});
