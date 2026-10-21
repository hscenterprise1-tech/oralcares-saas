<?php

namespace App\Console\Commands;

use App\BillingStatus;
use App\Models\Clinic;
use App\Models\ClinicWhatsAppSettings;
use App\Models\PatientBilling;
use App\Models\PatientFollowUp;
use App\Models\PatientSessionPackage;
use App\Services\Communication\PatientMessaging;
use App\Services\WhatsApp\ClinicWhatsApp;
use App\Support\ClinicClock;
use Illuminate\Console\Command;

/**
 * Patient messages that no single change triggers, only the calendar:
 * balance due, balance overdue, a follow-up's own day, and a package about
 * to expire. Only clinics whose WhatsApp carries that message are read (a
 * small set), each bounded per run; event keys make reruns harmless.
 */
class QueueScheduledPatientMessages extends Command
{
    protected $signature = 'clinic:queue-scheduled-messages';

    protected $description = 'Queue due, overdue, follow-up-day and package-expiry patient messages for clinics that send them';

    private const PER_CLINIC = 500;

    public function handle(PatientMessaging $messages, ClinicWhatsApp $whatsApp): int
    {
        $today = ClinicClock::now()->startOfDay();
        $overdueDays = (int) config('clinic_whatsapp.overdue_after_days', 7);
        $queued = 0;

        ClinicWhatsAppSettings::query()->withoutGlobalScopes()->where('enabled', true)
            ->chunkById(200, function ($settings) use ($messages, $whatsApp, $today, $overdueDays, &$queued) {
                foreach ($settings as $setting) {
                    $clinic = Clinic::query()->find($setting->clinic_id);
                    if (! $clinic) {
                        continue;
                    }
                    $open = fn () => PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
                        ->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])->where('outstanding_balance', '>', 0)
                        ->orderBy('id')->limit(self::PER_CLINIC);

                    if ($whatsApp->carries($clinic, 'payment_due')) {
                        foreach ($open()->whereBetween('issued_at', [$today->subDays($overdueDays - 1), $today->subDay()->endOfDay()])->get() as $bill) {
                            $messages->balanceOpen($bill, overdue: false);
                            $queued++;
                        }
                    }
                    if ($whatsApp->carries($clinic, 'payment_overdue')) {
                        foreach ($open()->where('issued_at', '<', $today->subDays($overdueDays - 1))->get() as $bill) {
                            $messages->balanceOpen($bill, overdue: true);
                            $queued++;
                        }
                    }
                    if ($whatsApp->carries($clinic, 'follow_up_due')) {
                        $due = PatientFollowUp::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', PatientFollowUp::DUE)
                            ->whereOnDate('due_on', $today)->orderBy('id')->limit(self::PER_CLINIC)->get();
                        foreach ($due as $followUp) {
                            $messages->followUpToday($followUp);
                            $queued++;
                        }
                    }
                    if ($whatsApp->carries($clinic, 'physio_package_expiry')) {
                        $ending = PatientSessionPackage::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('status', 'active')
                            ->whereBetween('valid_until', [$today->toDateString(), $today->addDays((int) config('clinic_whatsapp.package_expiry_days', 7))->toDateString()])
                            ->orderBy('id')->limit(self::PER_CLINIC)->get();
                        foreach ($ending as $package) {
                            $messages->packageExpiring($package);
                            $queued++;
                        }
                    }
                }
            }, 'id');

        $this->info("Checked scheduled patient messages ({$queued} considered).");

        return self::SUCCESS;
    }
}
