<?php

namespace App\Services\CRM;

use App\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The one place a deal's history gets written.
 *
 * Before this, an event reached the timeline only if the code path that caused
 * it happened to insert an activity row, and most did not. Calls, WhatsApp and
 * quotation approvals wrote history; demos, trials, payments, documents,
 * branch edits, contact edits and assignment changes did not. Field edits went
 * through a hand-maintained label map that covered 23 of the 40 editable
 * fields, so four of the six section editors could change a deal and leave no
 * trace.
 *
 * The rule this class exists to make cheap: if it changed the deal, it is in
 * the timeline. Every mutation calls one of these methods, and the timeline
 * reads what they wrote.
 *
 * Descriptions are written for the person reading the deal six weeks later,
 * not for the developer: "Stage moved from Contacted to Demo Scheduled", not
 * "status: contacted -> demo_scheduled".
 */
class DealActivityRecorder
{
    /**
     * Fields whose changes are worth a line of history, and how to name them.
     *
     * Everything the create form captures is here — the previous map stopped
     * at 23 entries and silently dropped the rest, which is how a rep could
     * change a deal's specialties, modules, owner, plan or close probability
     * with nothing to show for it.
     *
     * @var array<string, string>
     */
    public const FIELD_LABELS = [
        'company_name' => 'Company name',
        'contact_name' => 'Contact name',
        'email' => 'Email',
        'phone' => 'Phone',
        'address' => 'Address',
        'area' => 'Locality',
        'city' => 'City',
        'state' => 'State',
        'country' => 'Country',
        'postal_code' => 'Pincode',
        'source' => 'Source',
        'organization_type' => 'Organisation type',
        'healthcare_specialty' => 'Specialty',
        'healthcare_specialties' => 'Specialties',
        'organization_size' => 'Organisation size',
        'branch_count' => 'Branches',
        'expected_users' => 'Expected users',
        'expected_mrr' => 'Expected monthly value',
        'expected_arr' => 'Expected annual value',
        'probability_to_close' => 'Chance of closing',
        'priority' => 'Priority',
        'next_followup_at' => 'Next follow-up',
        'followup_notes' => 'Notes',
        'current_software' => 'Current software',
        'migration_required' => 'Data migration needed',
        'competitors' => 'Competitors',
        'deployment_preference' => 'Deployment preference',
        'expected_go_live_date' => 'Go-live date',
        'required_modules' => 'Modules required',
        'required_integrations' => 'Integrations required',
        'product_interest' => 'Product interest',
        'selected_plan_id' => 'Package',
        'assigned_user_id' => 'Owner',
        'assigned_team_id' => 'Team',
        'lost_reason' => 'Lost reason',
        'lost_competitor' => 'Lost to',
        'winback_scheduled_at' => 'Win-back scheduled',
    ];

    /**
     * Fields stored as a flag.
     *
     * The value before an edit comes from the raw attributes, where a boolean
     * is the string "1" or "0"; the value after comes from the request, where
     * it is a real boolean. Left alone the timeline read "Data migration
     * needed: 1 → ", which is neither side of the question.
     *
     * @var list<string>
     */
    protected const BOOLEAN_FIELDS = ['migration_required'];

    /**
     * Write one event.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(
        Lead $lead,
        ActivityType $type,
        string $description,
        array $properties = [],
        ?User $user = null,
    ): Activity {
        return $lead->activities()->create([
            'user_id' => ($user ?? Auth::user())?->id,
            'type' => $type,
            'description' => $description,
            'properties' => $properties ?: null,
        ]);
    }

    /**
     * Record what an edit actually changed on the deal itself.
     *
     * Takes the attributes as they were before the write, so it can report the
     * old value rather than guessing. Returns null when nothing meaningful
     * moved, so a save that changed nothing does not manufacture history.
     *
     * @param  array<string, mixed>  $before  Raw attributes before the update
     * @param  array<string, mixed>  $payload  The fields the update was given
     */
    public function fieldChanges(Lead $lead, array $before, array $payload, ?User $user = null): ?Activity
    {
        $changes = [];

        foreach ($payload as $field => $new) {
            $label = self::FIELD_LABELS[$field] ?? null;

            if (! $label) {
                continue;
            }

            $old = $before[$field] ?? null;

            if ($this->normalise($old) === $this->normalise($new)) {
                continue;
            }

            $isFlag = in_array($field, self::BOOLEAN_FIELDS, true);

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'from' => $isFlag ? $this->yesNo($old) : $this->readable($old),
                'to' => $isFlag ? $this->yesNo($new) : $this->readable($new),
            ];
        }

        if ($changes === []) {
            return null;
        }

        $summary = collect($changes)
            ->map(fn (array $c) => "{$c['label']}: {$c['from']} → {$c['to']}")
            ->implode('; ');

        return $this->record(
            $lead,
            ActivityType::RecordUpdated,
            'Deal details updated — '.$summary,
            ['changes' => $changes],
            $user,
        );
    }

    /**
     * Record a change to something that hangs off the deal — a contact, a
     * branch, a demo, a trial, a quotation, an agreement, a payment, a task.
     *
     * These are their own records, so a field-level diff would be noise. What
     * the timeline needs is that it happened, to what, and by whom.
     *
     * @param  array<string, mixed>  $properties
     */
    public function relatedRecord(
        Lead $lead,
        ActivityType $type,
        string $verb,
        string $subject,
        array $properties = [],
        ?User $user = null,
    ): Activity {
        return $this->record($lead, $type, trim("{$verb} {$subject}"), $properties, $user);
    }

    /**
     * Compare two values without tripping over how they were stored.
     *
     * A JSON column reads back as an array on one side and arrives as an array
     * on the other, but the raw attribute is a string; a decimal reads back as
     * "45000.00" against an incoming 45000. Neither is a change.
     */
    protected function normalise(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        if (is_array($value)) {
            return json_encode(array_values($value)) ?: '';
        }

        if (is_string($value) && str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return json_encode(array_values($decoded)) ?: '';
            }
        }

        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return (string) $value;
    }

    /** A flag, in the words somebody would use. */
    protected function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    /** The same value, written for a person. */
    protected function readable(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y');
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $value));
        }

        if (is_string($value) && str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded === [] ? '—' : implode(', ', array_map(fn ($v) => (string) $v, $decoded));
            }
        }

        return (string) $value;
    }
}
