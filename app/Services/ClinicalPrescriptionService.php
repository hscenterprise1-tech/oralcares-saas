<?php

namespace App\Services;

use App\ClinicDocument;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicMedicine;
use App\Models\PatientTimelineEvent;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Services\Communication\PatientMessaging;
use App\Services\Numbering\ClinicDocumentNumbers;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of prescriptions (Prescription Studio).
 *
 * A prescription is drafted against a consultation that is still open, built
 * line by line, then finalised: numbered from the clinic's prescription
 * series, signed (a hash of its lines is kept) and never edited again. A
 * finalised prescription can only be cancelled, with a reason. Every
 * medicine written is remembered in the clinic's own formulary.
 *
 * Authorisation is the caller's (`PrescriptionPolicy`); this class enforces
 * ownership, state and order.
 */
class ClinicalPrescriptionService
{
    public function __construct(
        protected ClinicDocumentNumbers $numbers,
        protected PatientMessaging $messages,
    ) {}

    /**
     * The consultation's open draft, or a new one.
     */
    public function draftFor(ClinicalEncounter $encounter, ?ClinicEmployee $prescriber): Prescription
    {
        return DB::transaction(function () use ($encounter, $prescriber) {
            $encounter = $this->lockOpenEncounter($encounter);

            return Prescription::query()->withoutGlobalScopes()
                ->where('encounter_id', $encounter->id)->where('status', Prescription::DRAFT)->first()
                ?? Prescription::query()->create([
                    'organization_id' => $encounter->organization_id,
                    'clinic_id' => $encounter->clinic_id,
                    'encounter_id' => $encounter->id,
                    'patient_id' => $encounter->patient_id,
                    'prescribed_by' => auth()->id(),
                    'prescriber_id' => $prescriber?->id ?? $encounter->doctor_id,
                    'status' => Prescription::DRAFT,
                ]);
        });
    }

    /**
     * @param  array{medicine_id?: ?int, drug_name?: ?string, form?: ?string, strength?: ?string, dosage: string, frequency: string, duration: string, route?: ?string, timing?: ?string, instructions?: ?string}  $line
     */
    public function addItem(Prescription $prescription, array $line): PrescriptionItem
    {
        return DB::transaction(function () use ($prescription, $line) {
            $prescription = $this->lockDraft($prescription);

            if ($prescription->items()->count() >= config('clinic_prescriptions.max_items', 30)) {
                throw new DomainException('A prescription can hold at most '.config('clinic_prescriptions.max_items', 30).' medicines.');
            }

            return $prescription->items()->create([
                ...$this->line($prescription, $line),
                'sort_order' => (int) $prescription->items()->max('sort_order') + 1,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function updateItem(Prescription $prescription, PrescriptionItem $item, array $line): PrescriptionItem
    {
        return DB::transaction(function () use ($prescription, $item, $line) {
            $prescription = $this->lockDraft($prescription);
            $this->assertLine($prescription, $item);
            $item->update($this->line($prescription, $line));

            return $item;
        });
    }

    public function removeItem(Prescription $prescription, PrescriptionItem $item): void
    {
        DB::transaction(function () use ($prescription, $item) {
            $prescription = $this->lockDraft($prescription);
            $this->assertLine($prescription, $item);
            $item->delete();
        });
    }

    public function updateNotes(Prescription $prescription, ?string $notes): Prescription
    {
        return DB::transaction(function () use ($prescription, $notes) {
            $prescription = $this->lockDraft($prescription);
            $prescription->update(['notes' => ($notes = trim((string) $notes)) === '' ? null : $notes]);

            return $prescription;
        });
    }

    /**
     * Sign and number the prescription. From here it is printed, never edited.
     */
    public function finalize(Prescription $prescription, ?ClinicEmployee $by): Prescription
    {
        return DB::transaction(function () use ($prescription, $by) {
            $prescription = $this->lockDraft($prescription);
            $lines = $prescription->items()->get();

            if ($lines->isEmpty()) {
                throw new DomainException('Add at least one medicine before finalising the prescription.');
            }

            $clinic = Clinic::query()->findOrFail($prescription->clinic_id);
            $now = now();

            $prescription->fill([
                'prescription_number' => $this->numbers->next(ClinicDocument::Prescription, $clinic),
                'status' => Prescription::FINALIZED,
                'finalized_at' => $now,
                'finalized_by' => $by?->id,
                'prescriber_id' => $prescription->prescriber_id ?? $by?->id,
            ]);
            $prescription->signature_hash = hash('sha256', json_encode([
                $prescription->id, $prescription->prescription_number, $prescription->clinic_id, $prescription->patient_id,
                $prescription->prescriber_id, $prescription->notes, $now->toIso8601String(),
                $lines->map(fn (PrescriptionItem $line) => $line->only(['drug_name', 'form', 'strength', 'dosage', 'frequency', 'duration', 'route', 'timing', 'instructions']))->all(),
            ]));
            $prescription->save();

            $this->remember($lines);

            PatientTimelineEvent::create([
                'organization_id' => $prescription->organization_id,
                'clinic_id' => $prescription->clinic_id,
                'patient_id' => $prescription->patient_id,
                'actor_id' => auth()->id(),
                'event_type' => 'prescription_issued',
                'context' => [
                    'prescription_id' => $prescription->id,
                    'encounter_id' => $prescription->encounter_id,
                    'prescription_number' => $prescription->prescription_number,
                    'item_count' => $lines->count(),
                ],
            ]);

            DB::afterCommit(fn () => $this->messages->prescriptionIssued($prescription));

            return $prescription;
        });
    }

    /**
     * Withdraw a finalised prescription. It stays on the record, marked
     * cancelled with who and why.
     */
    public function cancel(Prescription $prescription, ClinicEmployee $by, string $reason): Prescription
    {
        return DB::transaction(function () use ($prescription, $by, $reason) {
            /** @var Prescription $prescription */
            $prescription = Prescription::query()->withoutGlobalScopes()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();

            if (! $prescription->isFinalized()) {
                throw new DomainException('Only a finalised prescription can be cancelled; a draft is simply edited.');
            }
            if (trim($reason) === '') {
                throw new InvalidArgumentException('Say why the prescription is being cancelled.');
            }

            $prescription->update([
                'status' => Prescription::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => trim($reason),
            ]);

            PatientTimelineEvent::create([
                'organization_id' => $prescription->organization_id,
                'clinic_id' => $prescription->clinic_id,
                'patient_id' => $prescription->patient_id,
                'actor_id' => auth()->id(),
                'event_type' => 'prescription_cancelled',
                'context' => ['prescription_id' => $prescription->id, 'prescription_number' => $prescription->prescription_number, 'reason' => trim($reason)],
            ]);

            return $prescription;
        });
    }

    /**
     * When a consultation is signed: its draft prescriptions with medicines
     * are finalised with it; empty drafts are discarded.
     */
    public function settleDraftsOf(ClinicalEncounter $encounter, ClinicEmployee $doctor): void
    {
        Prescription::query()->withoutGlobalScopes()
            ->where('encounter_id', $encounter->id)->where('status', Prescription::DRAFT)
            ->get()
            ->each(function (Prescription $draft) use ($doctor) {
                $draft->items()->exists() ? $this->finalize($draft, $doctor) : $draft->delete();
            });
    }

    /**
     * Write and finalise a prescription in one step (callers outside the
     * studio, e.g. treatment planning).
     *
     * @param  array{notes?: ?string, details?: array<string, mixed>}  $prescriptionData
     * @param  array<int, array<string, mixed>>  $items
     */
    public function issuePrescription(ClinicalEncounter $encounter, array $prescriptionData, array $items, ?ClinicEmployee $prescriber = null): Prescription
    {
        return DB::transaction(function () use ($encounter, $prescriptionData, $items, $prescriber) {
            $encounter = $this->lockOpenEncounter($encounter);
            $prescriber ??= ClinicEmployee::query()->withoutGlobalScopes()
                ->where('clinic_id', $encounter->clinic_id)->where('user_id', auth()->id())->first();

            $prescription = Prescription::query()->create([
                'organization_id' => $encounter->organization_id,
                'clinic_id' => $encounter->clinic_id,
                'encounter_id' => $encounter->id,
                'patient_id' => $encounter->patient_id,
                'prescribed_by' => auth()->id(),
                'prescriber_id' => $prescriber?->id ?? $encounter->doctor_id,
                'status' => Prescription::DRAFT,
                'notes' => $prescriptionData['notes'] ?? null,
                'details' => $prescriptionData['details'] ?? [],
            ]);

            foreach ($items as $item) {
                $this->addItem($prescription, $item);
            }

            return $this->finalize($prescription, $prescriber);
        });
    }

    /**
     * The line as it will be printed. A formulary medicine fills in its name,
     * form and strength; a typed one is taken as written.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function line(Prescription $prescription, array $line): array
    {
        $medicine = null;
        if (! empty($line['medicine_id'])) {
            $medicine = ClinicMedicine::query()->withoutGlobalScopes()
                ->where('clinic_id', $prescription->clinic_id)->whereKey($line['medicine_id'])->first()
                ?? throw new InvalidArgumentException('That medicine is not in this clinic\'s formulary.');
        }

        $name = trim((string) ($line['drug_name'] ?? $medicine?->name ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Name the medicine.');
        }
        foreach (['dosage', 'frequency', 'duration'] as $required) {
            if (trim((string) ($line[$required] ?? '')) === '') {
                throw new InvalidArgumentException("Give the {$required} for {$name}.");
            }
        }

        $clean = fn (string $key) => ($value = trim((string) ($line[$key] ?? ''))) === '' ? null : $value;

        return [
            'medicine_id' => $medicine?->id,
            'drug_master_id' => $line['drug_master_id'] ?? null,
            'drug_name' => $name,
            'form' => $clean('form') ?? $medicine?->form,
            'strength' => $clean('strength') ?? $medicine?->strength,
            'dosage' => trim((string) $line['dosage']),
            'frequency' => trim((string) $line['frequency']),
            'duration' => trim((string) $line['duration']),
            'route' => $clean('route'),
            'timing' => $clean('timing'),
            'instructions' => $clean('instructions'),
            'details' => $line['details'] ?? [],
        ];
    }

    /**
     * Put each finalised medicine in the clinic's formulary (or count another
     * use of it), keeping the dose it was last written with.
     *
     * @param  iterable<PrescriptionItem>  $lines
     */
    private function remember(iterable $lines): void
    {
        foreach ($lines as $line) {
            $key = ClinicMedicine::lookupKey($line->drug_name, $line->strength, $line->form);
            $defaults = [
                'default_dosage' => $line->dosage,
                'default_frequency' => $line->frequency,
                'default_duration' => $line->duration,
                'default_timing' => $line->timing,
                'default_instructions' => $line->instructions,
                'last_used_at' => now(),
            ];

            $medicine = ClinicMedicine::query()->withoutGlobalScopes()->where('clinic_id', $line->clinic_id)->where('lookup_key', $key)->first();

            if ($medicine) {
                $medicine->forceFill([...$defaults, 'use_count' => $medicine->use_count + 1])->save();
            } else {
                $medicine = ClinicMedicine::query()->create([
                    'clinic_id' => $line->clinic_id,
                    'name' => $line->drug_name,
                    'form' => $line->form,
                    'strength' => $line->strength,
                    'lookup_key' => $key,
                    'use_count' => 1,
                    ...$defaults,
                ]);
            }

            if ($line->medicine_id === null) {
                $line->forceFill(['medicine_id' => $medicine->id])->saveQuietly();
            }
        }
    }

    private function lockOpenEncounter(ClinicalEncounter $encounter): ClinicalEncounter
    {
        /** @var ClinicalEncounter $locked */
        $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isEditable()) {
            throw new DomainException('This consultation is signed. Start a new consultation to prescribe.');
        }

        return $locked;
    }

    private function lockDraft(Prescription $prescription): Prescription
    {
        /** @var Prescription $locked */
        $locked = Prescription::query()->withoutGlobalScopes()->whereKey($prescription->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isDraft()) {
            throw new DomainException('This prescription is finalised and cannot be changed. Cancel it and write a new one.');
        }

        return $locked;
    }

    private function assertLine(Prescription $prescription, PrescriptionItem $item): void
    {
        if ($item->prescription_id !== $prescription->id) {
            throw new InvalidArgumentException('That line belongs to a different prescription.');
        }
    }
}
