<?php

namespace App\Http\Controllers\Company\Crm;

use App\ActivityType;
use App\ContactRole;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Lead;
use App\Services\CRM\DealActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The people on a deal, managed from the deal.
 *
 * The global contacts endpoints exist and serve the Contacts page, but they
 * are the wrong door for this: they authorize against the Contact rather than
 * the deal, they never stamp `organization_id` — which is NOT NULL, so a
 * contact added from a deal threw — they drop `whatsapp` on every update, and
 * nothing stopped two contacts on one deal both claiming to be primary.
 *
 * Deal-scoped, authorized by the deal, with the primary contact exclusive by
 * construction. The Contacts module is untouched.
 */
class DealContactController extends Controller
{
    public function __construct(protected DealActivityRecorder $activity) {}

    public function store(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $this->validated($request);

        $contact = DB::transaction(function () use ($lead, $validated, $request) {
            $contact = $lead->contacts()->create($validated + [
                'organization_id' => $lead->organization_id,
                'is_primary' => false,
            ]);

            $this->activity->relatedRecord(
                $lead,
                ActivityType::RecordCreated,
                'Contact added —',
                trim($contact->first_name.' '.$contact->last_name),
                ['contact_id' => $contact->id],
                $request->user(),
            );

            return $contact;
        });

        return back()->with('success', trim($contact->first_name.' '.$contact->last_name).' added.');
    }

    public function update(Request $request, Lead $lead, Contact $contact)
    {
        Gate::authorize('update', $lead);
        abort_unless($contact->lead_id === $lead->id, 404);

        $contact->update($this->validated($request));

        $this->activity->relatedRecord(
            $lead,
            ActivityType::RecordUpdated,
            'Contact updated —',
            trim($contact->first_name.' '.$contact->last_name),
            ['contact_id' => $contact->id],
            $request->user(),
        );

        return back()->with('success', 'Contact updated.');
    }

    /**
     * Make this person the one the deal is about.
     *
     * The primary contact is also the deal's own `contact_name` / `email` /
     * `phone` — the columns the list, the search and the duplicate check all
     * read — so promoting somebody moves those too. Otherwise the deal would
     * name one person and its contact card another, which is the drift this
     * rebuild has been closing everywhere else.
     */
    public function promote(Request $request, Lead $lead, Contact $contact)
    {
        Gate::authorize('update', $lead);
        abort_unless($contact->lead_id === $lead->id, 404);

        if ($contact->is_primary) {
            return back()->with('success', 'Already the main contact.');
        }

        DB::transaction(function () use ($lead, $contact, $request) {
            $previous = $lead->contacts()->where('is_primary', true)->first();

            $lead->contacts()->where('is_primary', true)->update(['is_primary' => false]);
            $contact->update(['is_primary' => true]);

            $lead->forceFill([
                'contact_name' => trim($contact->first_name.' '.$contact->last_name),
                'email' => $contact->email ?: $lead->email,
                'phone' => $contact->phone ?: $lead->phone,
            ])->save();

            $this->activity->relatedRecord(
                $lead,
                ActivityType::RecordUpdated,
                'Main contact changed —',
                ($previous ? trim($previous->first_name.' '.$previous->last_name).' → ' : '')
                    .trim($contact->first_name.' '.$contact->last_name),
                ['contact_id' => $contact->id, 'previous_contact_id' => $previous?->id],
                $request->user(),
            );
        });

        return back()->with('success', 'Main contact changed.');
    }

    public function destroy(Request $request, Lead $lead, Contact $contact)
    {
        Gate::authorize('update', $lead);
        abort_unless($contact->lead_id === $lead->id, 404);

        /*
         * The main contact cannot simply be removed — the deal's own name,
         * email and phone come from them. Promote somebody else first, which
         * moves those columns across, and then this person is removable.
         */
        if ($contact->is_primary) {
            return back()->withErrors([
                'contact' => 'Make somebody else the main contact before removing this one.',
            ]);
        }

        $name = trim($contact->first_name.' '.$contact->last_name);
        $contact->delete();

        $this->activity->relatedRecord(
            $lead,
            ActivityType::RecordDeleted,
            'Contact removed —',
            $name,
            ['contact_id' => $contact->id],
            $request->user(),
        );

        return back()->with('success', $name.' removed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            /* Dropped by the global endpoint on every update; the deal captures it. */
            'whatsapp' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'decision_role' => ['nullable', Rule::enum(ContactRole::class)],
        ]);
    }
}
