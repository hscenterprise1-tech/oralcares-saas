<?php

namespace App\Services\Reception;

use App\Models\Clinic;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\Clinical\ClinicalContext;
use App\Services\Patient\ClinicSpecialties;
use App\Services\PatientFieldConfigService;
use App\Support\Clinical\DefaultClinicalCatalogue;
use Illuminate\Validation\ValidationException;

/**
 * What today's visit is for: specialty and practice area (from the
 * server-resolved `ClinicalContext`), the reason (a catalogue reason offered
 * for that context, or "Other" with words), the visit type (chosen, else the
 * one the reason implies). This is the only validation of these; check-in
 * and register-and-check-in both use it. (Vitals are no longer taken at the
 * desk; readings recorded before are kept on the visit, unread.)
 */
final class VisitContext
{
    /** The reason key that means "Other" (typed words, no catalogue entry). */
    public const OTHER_REASON = 'other';

    public function __construct(
        public readonly ?int $clinicSpecialtyId = null,
        public readonly ?string $specialtyKey = null,
        public readonly ?string $visitTypeKey = null,
        public readonly ?string $reason = null,
        public readonly ?string $practiceArea = null,
        public readonly ?string $reasonKey = null,
    ) {}

    /**
     * @param  array{visit_specialty?: ?string, visit_type?: ?string, visit_reason?: ?string, visit_reason_key?: ?string}  $input
     */
    public static function fromInput(Clinic $clinic, array $input): self
    {
        $specialtyKey = ($input['visit_specialty'] ?? null) ?: null;
        $specialtyId = null;
        if ($specialtyKey !== null) {
            $specialty = app(ClinicSpecialties::class)->active($clinic, $specialtyKey)
                ?? throw ValidationException::withMessages(['visit_specialty' => 'That specialty is not active at this clinic.']);
            $specialtyId = $specialty->id;
        }
        [$reasonKey, $reason, $impliedType] = self::reason($clinic, $input, $specialtyKey, null);

        return new self($specialtyId, $specialtyKey, self::visitType($clinic, $input, $specialtyKey, $impliedType), $reason, null, $reasonKey);
    }

    /**
     * Today's visit in a context the server resolved: its specialty and area
     * are the context's, never the request's.
     *
     * @param  array{visit_type?: ?string, visit_reason?: ?string, visit_reason_key?: ?string}  $input
     */
    public static function fromResolved(Clinic $clinic, ClinicalContext $context, array $input): self
    {
        [$reasonKey, $reason, $impliedType] = self::reason($clinic, $input, $context->specialtyKey, $context->practiceArea);

        return new self(
            $context->clinicSpecialtyId,
            $context->specialtyKey,
            self::visitType($clinic, $input, $context->specialtyKey, $impliedType),
            $reason,
            $context->practiceArea,
            $reasonKey,
        );
    }

    /**
     * @return array{clinic_specialty_id?: int, practice_area_key?: string, visit_type_key?: string, visit_reason?: string, visit_reason_key?: string}
     */
    public function attributes(): array
    {
        return array_filter([
            'clinic_specialty_id' => $this->clinicSpecialtyId,
            'practice_area_key' => $this->practiceArea,
            'visit_type_key' => $this->visitTypeKey,
            'visit_reason' => $this->reason,
            'visit_reason_key' => $this->reasonKey,
        ], fn ($value) => $value !== null);
    }

    /**
     * The visit type: the one chosen if offered; else the one the reason
     * implies, when the clinic offers it for this specialty.
     *
     * @param  array{visit_type?: ?string}  $input
     */
    private static function visitType(Clinic $clinic, array $input, ?string $specialtyKey, ?string $impliedType): ?string
    {
        $typeKey = ($input['visit_type'] ?? null) ?: null;
        $offered = collect(app(ClinicalCatalogue::class)->effective($clinic->id, DefaultClinicalCatalogue::KIND_VISIT_TYPE, $specialtyKey))->pluck('key');
        if ($typeKey !== null && ! $offered->contains($typeKey)) {
            throw ValidationException::withMessages(['visit_type' => 'That visit type is not offered for this visit.']);
        }

        return $typeKey ?? ($impliedType !== null && $offered->contains($impliedType) ? $impliedType : null);
    }

    /**
     * The reason: a catalogue reason offered for this specialty and area
     * (stored by key and name), or "Other" with the words typed, or words
     * alone (older callers). The clinic decides whether a reason is
     * required, optional or not asked.
     *
     * @param  array{visit_reason?: ?string, visit_reason_key?: ?string}  $input
     * @return array{0: ?string, 1: ?string, 2: ?string} key, text, implied visit type
     */
    private static function reason(Clinic $clinic, array $input, ?string $specialtyKey, ?string $practiceArea): array
    {
        $state = app(PatientFlowConfigService::class)->checkIn($clinic)['visit_reason'];
        if ($state === PatientFieldConfigService::HIDDEN) {
            return [null, null, null];
        }

        $key = trim((string) ($input['visit_reason_key'] ?? '')) ?: null;
        $text = trim((string) ($input['visit_reason'] ?? '')) ?: null;
        if ($text !== null && mb_strlen($text) > 255) {
            throw ValidationException::withMessages(['visit_reason' => 'Keep the reason under 255 characters.']);
        }

        if ($key === self::OTHER_REASON) {
            if ($text === null) {
                throw ValidationException::withMessages(['visit_reason' => 'Say what the visit is for.']);
            }

            return [self::OTHER_REASON, $text, null];
        }

        if ($key !== null) {
            $item = collect(app(ClinicalCatalogue::class)->effective($clinic->id, DefaultClinicalCatalogue::KIND_VISIT_REASON, $specialtyKey, false, $practiceArea))->firstWhere('key', $key)
                ?? throw ValidationException::withMessages(['visit_reason_key' => 'That reason is not offered for this visit.']);

            return [$key, $item['name'], $item['category']];
        }

        if ($text === null && $state === PatientFieldConfigService::REQUIRED) {
            throw ValidationException::withMessages(['visit_reason' => 'Choose the reason for today\'s visit.']);
        }

        return [null, $text, null];
    }
}
