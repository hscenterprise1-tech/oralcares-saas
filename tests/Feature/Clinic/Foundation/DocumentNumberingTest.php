<?php

use App\ClinicDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\NumberingSequence;
use App\Models\Organization;
use App\Services\Numbering\ClinicDocumentNumbers;
use Carbon\CarbonImmutable;

/*
 * Human-facing clinic document numbers come from locked sequences, never a
 * random string or a row count, and are unique within their tenant.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 11:00', 'Asia/Kolkata'));
});

function numberingClinic(?Organization $organization = null): Clinic
{
    return Clinic::factory()->create(['organization_id' => ($organization ?? Organization::factory()->create())->id]);
}

it('numbers clinical documents per organisation and financial year', function (ClinicDocument $document, string $first) {
    $numbers = app(ClinicDocumentNumbers::class);
    $clinic = numberingClinic();

    expect($numbers->next($document, $clinic))->toBe($first)
        ->and($numbers->next($document, numberingClinic()))->toBe($first);
})->with([
    'encounter' => [ClinicDocument::Encounter, 'ENC-2627-000001'],
    'prescription' => [ClinicDocument::Prescription, 'RX-2627-000001'],
    'clinical order' => [ClinicDocument::ClinicalOrder, 'ORD-2627-000001'],
    'imaging' => [ClinicDocument::Imaging, 'IMG-2627-000001'],
    'lab order' => [ClinicDocument::LabOrder, 'LAB-2627-000001'],
]);

it('shares one clinical series between the clinics of one organisation, so numbers never repeat inside it', function () {
    $organization = Organization::factory()->create();
    $numbers = app(ClinicDocumentNumbers::class);

    expect($numbers->next(ClinicDocument::Encounter, numberingClinic($organization)))->toBe('ENC-2627-000001')
        ->and($numbers->next(ClinicDocument::Encounter, numberingClinic($organization)))->toBe('ENC-2627-000002');
});

it('keeps financial documents on their branch series and refuses one without a branch', function () {
    $clinic = numberingClinic();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
    $numbers = app(ClinicDocumentNumbers::class);

    expect($numbers->next(ClinicDocument::Receipt, $clinic, $branch))->toBe('RCT-2627-00001')
        ->and(fn () => $numbers->next(ClinicDocument::Invoice, $clinic))->toThrow(InvalidArgumentException::class);
});

it('refuses a branch of another clinic', function () {
    $clinic = numberingClinic();
    $other = numberingClinic();
    $theirs = Branch::factory()->create(['clinic_id' => $other->id, 'organization_id' => $other->organization_id]);

    expect(fn () => app(ClinicDocumentNumbers::class)->next(ClinicDocument::Invoice, $clinic, $theirs))->toThrow(InvalidArgumentException::class);
});

it('uses the invoice prefix a branch chose at onboarding and starts it at one', function () {
    $clinic = numberingClinic();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
    NumberingSequence::query()->create([
        'organization_id' => $clinic->organization_id, 'branch_id' => $branch->id, 'entity_type' => 'invoice',
        'prefix' => 'ALK-', 'current_number' => 0,
    ]);

    expect(app(ClinicDocumentNumbers::class)->next(ClinicDocument::Invoice, $clinic, $branch))->toBe('ALK-2627-00001');
});

it('turns the financial year on the clinic\'s own calendar day', function () {
    $clinic = numberingClinic();
    $numbers = app(ClinicDocumentNumbers::class);

    $this->travelTo(CarbonImmutable::parse('2027-03-31 23:30', 'Asia/Kolkata'));
    expect($numbers->next(ClinicDocument::Encounter, $clinic))->toBe('ENC-2627-000001');

    $this->travelTo(CarbonImmutable::parse('2027-04-01 00:30', 'Asia/Kolkata'));
    expect($numbers->next(ClinicDocument::Encounter, $clinic))->toBe('ENC-2728-000001');
});
