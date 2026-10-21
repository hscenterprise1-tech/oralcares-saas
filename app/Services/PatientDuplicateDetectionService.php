<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientSpecialty;
use App\PatientStatus;
use App\Services\Patient\PatientBranchAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Possible existing records for a patient about to be registered: the same
 * phone (either number, either slot), the same email, or the same name with
 * the same date of birth. Searches the whole clinic — archived patients
 * included, since re-registering one is exactly the mistake to catch — and
 * every probe leads with `clinic_id` on an index.
 *
 * Used by the live check on the form and, authoritatively, by
 * `PatientController::store()`, which refuses to register over a match the
 * user has not explicitly acknowledged.
 */
class PatientDuplicateDetectionService
{
    public const LIMIT = 10;

    /**
     * @param  array{phone?: ?string, alternate_phone?: ?string, email?: ?string, first_name?: ?string, last_name?: ?string, name?: ?string, date_of_birth?: ?string}  $criteria
     * @return Collection<int, Patient> each with a `matched_on` list (phone, email, name_dob)
     */
    public function detectDuplicates(Clinic $clinic, array $criteria): Collection
    {
        $phones = collect([$criteria['phone'] ?? null, $criteria['alternate_phone'] ?? null])
            ->flatMap(fn (?string $phone) => self::phoneVariants($phone))->unique()->values()->all();
        $email = filled($criteria['email'] ?? null) ? trim((string) $criteria['email']) : null;
        $emails = $email ? array_values(array_unique([$email, Str::lower($email)])) : [];
        [$first, $last] = $this->nameParts($criteria);
        $dob = filled($criteria['date_of_birth'] ?? null) ? (string) $criteria['date_of_birth'] : null;
        $byNameAndDob = $first !== null && $dob !== null;

        if ($phones === [] && $emails === [] && ! $byNameAndDob) {
            return collect();
        }

        $matches = Patient::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            // A merged duplicate is not a person to register against: its survivor is (and matches on its own).
            ->where('status', '!=', PatientStatus::Merged->value)
            ->where(function ($query) use ($phones, $emails, $byNameAndDob, $first, $last, $dob) {
                if ($phones !== []) {
                    $query->orWhereIn('phone', $phones)->orWhereIn('alternate_phone', $phones);
                }
                if ($emails !== []) {
                    $query->orWhereIn('email', $emails);
                }
                if ($byNameAndDob) {
                    // The date of birth narrows to a handful of rows first.
                    $query->orWhere(fn ($sub) => $sub->whereDate('date_of_birth', $dob)
                        ->whereRaw('lower(first_name) = ?', [Str::lower($first)])
                        ->when($last !== null, fn ($withLast) => $withLast->whereRaw('lower(coalesce(last_name, \'\')) = ?', [Str::lower($last)])));
                }
            })
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return $matches->each(function (Patient $patient) use ($phones, $emails, $byNameAndDob, $first, $dob) {
            $patient->setAttribute('matched_on', array_values(array_filter([
                $phones !== [] && (in_array($patient->phone, $phones, true) || in_array($patient->alternate_phone, $phones, true)) ? 'phone' : null,
                $emails !== [] && in_array(Str::lower((string) $patient->email), array_map(Str::lower(...), $emails), true) ? 'email' : null,
                $byNameAndDob && $patient->date_of_birth?->toDateString() === substr((string) $dob, 0, 10) && Str::lower((string) $patient->first_name) === Str::lower((string) $first) ? 'name_dob' : null,
            ])));
        });
    }

    /**
     * What a match may show the person registering: enough to recognise the
     * patient, nothing more (the user may not be allowed to open the record).
     *
     * A match at none of the branches this member may open
     * (`PatientBranchAccess`) is shown masked — initials, what matched, and
     * `restricted: true`; no UHID, age, sex, birth date, phone hint,
     * specialties or branch — so the check can stop a second registration
     * without disclosing another branch's patient. Its id is kept only so it
     * can be acknowledged with a reason; it opens nothing and cannot be
     * checked in here.
     *
     * @param  Collection<int, Patient>  $matches
     * @return list<array{specialties: list<string>, last_seen: array{branch: string, at: string}|null, id: int, uhid: ?string, name: string, gender: ?string, age: ?int, date_of_birth: ?string, phone_hint: ?string, status: string, registered_on: ?string, matched_on: list<string>, restricted: bool}>
     */
    public function present(Collection $matches): array
    {
        // Where each match belongs and was last seen, for telling people apart:
        // two batched queries for the (at most LIMIT) matches, never one per match.
        $ids = $matches->pluck('id')->all();
        $clinicId = $matches->first()?->clinic_id;
        $specialties = $ids === [] ? collect() : DB::table('patient_specialties as ps')
            ->join('clinic_specialties as cs', 'cs.id', '=', 'ps.clinic_specialty_id')
            ->where('ps.clinic_id', $clinicId)->whereIn('ps.patient_id', $ids)->where('ps.status', PatientSpecialty::ACTIVE)
            ->get(['ps.patient_id', 'cs.specialty_key'])->groupBy('patient_id');
        $activity = $ids === [] ? collect() : DB::table('patient_branch_activity as a')
            ->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->where('a.clinic_id', $clinicId)->whereIn('a.patient_id', $ids)
            ->orderByDesc('a.last_seen_at')->get(['a.patient_id', 'a.branch_id', 'a.last_seen_at', 'b.name'])
            ->groupBy('patient_id');
        $lastSeen = $activity->map(fn ($rows) => $rows->first());
        $branchIds = app(PatientBranchAccess::class)->branchIds();
        $restricted = fn (Patient $patient): bool => $branchIds !== null
            && ($activity[$patient->id] ?? collect())->whereIn('branch_id', $branchIds)->isEmpty();

        return $matches->map(fn (Patient $patient) => $restricted($patient) ? [
            'specialties' => [],
            'last_seen' => null,
            'id' => $patient->id,
            'uhid' => null,
            'name' => $this->initials($patient),
            'gender' => null,
            'age' => null,
            'date_of_birth' => null,
            'phone_hint' => null,
            'status' => 'active',
            'registered_on' => null,
            'matched_on' => $patient->getAttribute('matched_on') ?? [],
            'restricted' => true,
        ] : [
            'specialties' => ($specialties[$patient->id] ?? collect())->pluck('specialty_key')->values()->all(),
            'last_seen' => isset($lastSeen[$patient->id])
                ? ['branch' => $lastSeen[$patient->id]->name, 'at' => substr((string) $lastSeen[$patient->id]->last_seen_at, 0, 10)]
                : null,
            'id' => $patient->id,
            'uhid' => $patient->uhid,
            'name' => $patient->full_name,
            'gender' => $patient->gender,
            'age' => $patient->age,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'phone_hint' => $patient->phone ? '••••'.substr((string) $patient->phone, -4) : null,
            'status' => $patient->status?->value ?? 'active',
            'registered_on' => $patient->created_at?->toDateString(),
            'matched_on' => $patient->getAttribute('matched_on') ?? [],
            'restricted' => false,
        ])->values()->all();
    }

    /** "A. R." — enough to tell two masked matches apart, not to identify anyone. */
    private function initials(Patient $patient): string
    {
        return collect([$patient->first_name, $patient->last_name])
            ->map(fn (?string $part) => trim((string) $part))->filter()
            ->map(fn (string $part) => Str::upper(mb_substr($part, 0, 1)).'.')
            ->implode(' ') ?: 'Patient';
    }

    /**
     * The number as typed and with spacing/punctuation removed, so "98765 43210"
     * finds "9876543210".
     *
     * @return list<string>
     */
    public static function phoneVariants(?string $phone): array
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter([$raw, preg_replace('/[\s\-().]/', '', $raw)])));
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array{0: ?string, 1: ?string}
     */
    private function nameParts(array $criteria): array
    {
        $first = trim((string) ($criteria['first_name'] ?? ''));
        $last = trim((string) ($criteria['last_name'] ?? ''));
        if ($first === '' && filled($criteria['name'] ?? null)) {
            $words = preg_split('/\s+/', trim((string) $criteria['name'])) ?: [];
            $first = (string) array_shift($words);
            $last = $words === [] ? '' : (string) end($words);
        }

        return [$first === '' ? null : $first, $last === '' ? null : $last];
    }
}
