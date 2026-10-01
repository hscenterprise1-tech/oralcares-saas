<?php

namespace App\Services\Staff;

use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\StaffDocument;
use App\Models\StaffDocumentRequirement;
use App\Models\StaffDocumentType;
use App\Support\ClinicClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The clinic's document catalogue and checklist, and where each staff member
 * stands against it.
 *
 * Every clinic gets its own editable catalogue, seeded once from
 * `config('clinic_staff.document_types')`. Requirements are stored per
 * staff type, so whether a document is required is always decided here on
 * the server.
 */
class StaffDocumentChecklist
{
    /**
     * Seed the clinic's catalogue and checklist on first use.
     */
    public function ensureCatalogue(Clinic $clinic): void
    {
        if (StaffDocumentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($clinic) {
            foreach (config('clinic_staff.document_types', []) as $position => $definition) {
                $type = StaffDocumentType::create([
                    'clinic_id' => $clinic->id,
                    'key' => $definition['key'],
                    'name' => $definition['name'],
                    'audience' => $definition['audience'],
                    'has_expiry' => $definition['has_expiry'],
                    'is_active' => true,
                    'position' => $position,
                ]);

                foreach (StaffDirectory::staffTypes() as $staffType => $meta) {
                    StaffDocumentRequirement::create([
                        'clinic_id' => $clinic->id,
                        'staff_document_type_id' => $type->id,
                        'staff_type' => $staffType,
                        'requirement' => $definition['default'][$meta['clinical'] ? 'clinical' : 'staff'] ?? StaffDocumentRequirement::OPTIONAL,
                    ]);
                }
            }
        });
    }

    /**
     * The catalogue with each type's requirement per staff type.
     *
     * @return list<array<string, mixed>>
     */
    public function matrix(Clinic $clinic): array
    {
        $this->ensureCatalogue($clinic);

        return StaffDocumentType::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->with(['requirements' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (StaffDocumentType $type) => [
                'id' => $type->id,
                'key' => $type->key,
                'name' => $type->name,
                'audience' => $type->audience,
                'has_expiry' => $type->has_expiry,
                'is_active' => $type->is_active,
                'requirements' => $type->requirements->pluck('requirement', 'staff_type')->all(),
            ])->all();
    }

    /**
     * The requirement of a type for a staff type: stored, else not applicable
     * when the type's audience excludes them, else optional.
     */
    public function requirementFor(StaffDocumentType $type, ?string $staffType): string
    {
        $staffType ??= 'other';
        $stored = $type->relationLoaded('requirements')
            ? $type->requirements->firstWhere('staff_type', $staffType)?->requirement
            : null;

        if ($stored) {
            return $stored;
        }

        $clinical = StaffDirectory::isClinical($staffType);

        return match ($type->audience) {
            'practitioner' => $clinical ? StaffDocumentRequirement::OPTIONAL : StaffDocumentRequirement::NOT_APPLICABLE,
            'staff' => $clinical ? StaffDocumentRequirement::NOT_APPLICABLE : StaffDocumentRequirement::OPTIONAL,
            default => StaffDocumentRequirement::OPTIONAL,
        };
    }

    /**
     * The staff member's file: each applicable type with its current
     * document, older versions, and a compliance summary.
     *
     * @return array{items: list<array<string, mixed>>, summary: array{required: int, complete: int, missing: list<string>, attention: int}}
     */
    public function forEmployee(Clinic $clinic, ClinicEmployee $employee): array
    {
        $this->ensureCatalogue($clinic);
        $today = ClinicClock::today();
        $staffType = StaffDirectory::effectiveType($employee);

        $types = StaffDocumentType::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->with(['requirements' => fn ($q) => $q->withoutGlobalScopes()->where('staff_type', $staffType)])
            ->orderBy('position')
            ->get();

        $documents = StaffDocument::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('clinic_employee_id', $employee->id)
            ->with(['uploader:id,name', 'reviewer:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->groupBy('staff_document_type_id');

        $items = [];
        $required = 0;
        $complete = 0;
        $missing = [];
        $attention = 0;

        foreach ($types as $type) {
            $requirement = $this->requirementFor($type, $staffType);
            $versions = $documents->get($type->id, collect());

            if ($requirement === StaffDocumentRequirement::NOT_APPLICABLE && $versions->isEmpty()) {
                continue;
            }
            if (! $type->is_active && $versions->isEmpty()) {
                continue;
            }

            $current = $versions->first(fn (StaffDocument $doc) => $doc->status !== StaffDocument::REPLACED);
            $state = $this->state($current, $today);

            if ($requirement === StaffDocumentRequirement::REQUIRED) {
                $required++;
                if ($state === 'verified' || $state === 'expiring_soon') {
                    $complete++;
                } else {
                    $missing[] = $type->name;
                }
            }
            if (in_array($state, ['pending_verification', 'rejected', 'expired', 'expiring_soon'], true)) {
                $attention++;
            }

            $items[] = [
                'type' => ['id' => $type->id, 'key' => $type->key, 'name' => $type->name, 'has_expiry' => $type->has_expiry],
                'requirement' => $requirement,
                'state' => $state,
                'current' => $current ? $this->present($current, $today) : null,
                'history' => $versions->reject(fn (StaffDocument $doc) => $current && $doc->is($current))
                    ->take(10)->map(fn (StaffDocument $doc) => $this->present($doc, $today))->values()->all(),
            ];
        }

        return ['items' => $items, 'summary' => ['required' => $required, 'complete' => $complete, 'missing' => $missing, 'attention' => $attention]];
    }

    /**
     * missing | pending_verification | verified | rejected | expired | expiring_soon.
     */
    public function state(?StaffDocument $document, $today): string
    {
        if (! $document) {
            return 'missing';
        }

        $expiry = $document->expiryState($today);
        if ($expiry === 'expired') {
            return 'expired';
        }
        if ($document->status === StaffDocument::VERIFIED) {
            return $expiry === 'expiring_soon' ? 'expiring_soon' : 'verified';
        }

        return $document->status === StaffDocument::REJECTED ? 'rejected' : 'pending_verification';
    }

    /**
     * @return array<string, mixed>
     */
    public function present(StaffDocument $document, $today): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'file_name' => $document->file_name,
            'size' => $document->file_size_bytes,
            'issued_on' => $document->issued_on?->toDateString(),
            'expires_on' => $document->expires_on?->toDateString(),
            'expiry_state' => $document->expiryState($today),
            'status' => $document->status,
            'state' => $this->state($document, $today),
            'notes' => $document->notes,
            'review_note' => $document->review_note,
            'replacement_requested' => $document->replacement_requested,
            'uploaded_by' => $document->uploader?->name,
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'reviewed_by' => $document->reviewer?->name,
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * Documents across the clinic that expire within the warning window or
     * already have, for a future reminder job and the staff list.
     *
     * @param  Collection<int, int>|list<int>  $employeeIds
     * @return Collection<int, int> employee id => documents needing attention
     */
    public function attentionCounts(Clinic $clinic, $employeeIds): Collection
    {
        $soon = ClinicClock::today()->addDays((int) config('clinic_staff.expiring_soon_days', 30))->toDateString();

        return StaffDocument::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereIn('clinic_employee_id', collect($employeeIds)->all())
            ->where('status', '!=', StaffDocument::REPLACED)
            ->where(fn ($q) => $q->whereIn('status', [StaffDocument::PENDING, StaffDocument::REJECTED])
                ->orWhere(fn ($e) => $e->whereNotNull('expires_on')->whereDate('expires_on', '<=', $soon)))
            ->selectRaw('clinic_employee_id, COUNT(*) as total')
            ->groupBy('clinic_employee_id')
            ->pluck('total', 'clinic_employee_id');
    }
}
