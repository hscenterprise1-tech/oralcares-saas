<?php

namespace App\Services\Patient;

use App\AppointmentStatus;
use App\BillingStatus;
use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\PatientBilling;
use App\Models\PatientCase;
use App\Models\User;
use App\Shared\Notification\DTO\NotificationActionDTO;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Support\ClinicClock;

/**
 * The morning summary, one in-app notification each:
 *
 * - each doctor: their bookings today, consultations still unsigned, results
 *   waiting for their review;
 * - the clinic owner: bookings today across the clinic, money still owed and
 *   cases waiting for the decision to close.
 *
 * Nothing is sent to someone with nothing to tell. Once a day per person
 * (`ClinicWorkAlerts::notify`); clinics turn it off with
 * `communication_config.morning_digest`.
 */
class ClinicMorningDigest
{
    public function __construct(protected ClinicWorkAlerts $alerts) {}

    public function enabled(Clinic $clinic): bool
    {
        return (bool) ($clinic->communication_config['morning_digest'] ?? true);
    }

    /** Returns how many summaries were sent. */
    public function run(Clinic $clinic): int
    {
        if (! $this->enabled($clinic)) {
            return 0;
        }

        return $this->doctors($clinic) + $this->owner($clinic);
    }

    private function doctors(Clinic $clinic): int
    {
        $day = ClinicClock::now()->toDateString();
        $doctors = ClinicEmployee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', 'active')->whereNotNull('user_id')
            ->whereHas('practitionerProfile', fn ($query) => $query->withoutGlobalScopes())->get(['id', 'user_id', 'name']);

        $sent = 0;
        foreach ($doctors as $doctor) {
            $bookings = $this->bookingsToday($clinic)->where('practitioner_id', $doctor->id)->count();
            $unsigned = ClinicalEncounter::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('doctor_id', $doctor->id)
                ->whereNull('finalized_at')->whereNull('voided_at')->count();
            $results = ClinicalOrderItem::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', ClinicalOrderItem::RESULTED)
                ->whereIn('clinical_order_id', ClinicalOrder::query()->withoutGlobalScopes()->select('id')->where('ordered_by', $doctor->user_id))->count();
            if ($bookings + $unsigned + $results === 0) {
                continue;
            }

            $sent += (int) $this->alerts->notify(User::query()->find($doctor->user_id), $clinic, "morning:{$doctor->user_id}:{$day}", new SendNotificationDTO(
                title: "Good morning — {$bookings} ".($bookings === 1 ? 'booking' : 'bookings').' today',
                message: collect([
                    $unsigned ? "{$unsigned} unsigned ".($unsigned === 1 ? 'consultation' : 'consultations') : null,
                    $results ? "{$results} ".($results === 1 ? 'result' : 'results').' to review' : null,
                ])->filter()->implode(' · ') ?: 'Nothing else is waiting for you.',
                category: NotificationCategory::DOCTOR,
                priority: NotificationPriority::NORMAL,
                clinicId: $clinic->id,
                actions: [new NotificationActionDTO('Open my day', route('clinic.consultations.index'))],
            ));
        }

        return $sent;
    }

    private function owner(Clinic $clinic): int
    {
        $day = ClinicClock::now()->toDateString();
        $ownerIds = ClinicMembership::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_primary_owner', true)
            ->where('status', ClinicMembershipStatus::Active)->pluck('user_id');
        $bookings = $this->bookingsToday($clinic)->count();
        $live = PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])->where('outstanding_balance', '>', 0);
        $owed = (float) (clone $live)->sum('outstanding_balance');
        $owingBills = (clone $live)->count();
        $toClose = PatientCase::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', PatientCase::COMPLETION_RECOMMENDED)->count();

        $sent = 0;
        foreach (User::query()->whereIn('id', $ownerIds)->get() as $owner) {
            $sent += (int) $this->alerts->notify($owner, $clinic, "morning_owner:{$owner->id}:{$day}", new SendNotificationDTO(
                title: "Good morning — {$bookings} ".($bookings === 1 ? 'booking' : 'bookings').' today',
                message: collect([
                    $owingBills ? '₹'.number_format($owed, 0).' owed on '.$owingBills.' '.($owingBills === 1 ? 'bill' : 'bills') : 'No money owed',
                    $toClose ? "{$toClose} ".($toClose === 1 ? 'case' : 'cases').' waiting for your decision to close' : null,
                ])->filter()->implode(' · '),
                category: NotificationCategory::INFORMATION,
                priority: NotificationPriority::NORMAL,
                clinicId: $clinic->id,
                actions: [new NotificationActionDTO('Open the clinic', route('clinic.dashboard'))],
            ));
        }

        return $sent;
    }

    /** Today's bookings that are still to happen or happening. */
    private function bookingsToday(Clinic $clinic)
    {
        $today = ClinicClock::now();

        return Appointment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereBetween('scheduled_at', [$today->startOfDay(), $today->endOfDay()])
            ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value]);
    }
}
