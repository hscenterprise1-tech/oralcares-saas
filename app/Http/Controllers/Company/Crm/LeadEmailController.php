<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadEmailThread;
use App\Services\CrmEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * CRM email channel.
 *
 * Authorization mirrors the WhatsApp channel: sending a message to a lead is
 * an act on that lead, so it requires `update` on the lead rather than a
 * separate permission. Consent, validation and failure handling all live in
 * CrmEmailService so no caller can skip them.
 */
class LeadEmailController extends Controller
{
    public function __construct(protected CrmEmailService $emails) {}

    public function store(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'to' => ['nullable', 'email', 'max:255'],
        ]);

        $thread = $this->emails->send($lead, $validated, $request->user());

        if ($thread->status === LeadEmailThread::STATUS_FAILED) {
            return back()->with('error', 'Email could not be delivered. It has been saved and can be retried.');
        }

        return back()->with('success', 'Email sent.');
    }

    public function retry(Request $request, Lead $lead, LeadEmailThread $thread)
    {
        Gate::authorize('update', $lead);

        // Route-model binding resolves the thread independently of the lead, so
        // the pairing has to be asserted or one lead's URL could retry another's.
        abort_unless((int) $thread->lead_id === (int) $lead->id, 404);

        $retried = $this->emails->retry($thread, $request->user());

        if ($retried->status === LeadEmailThread::STATUS_FAILED) {
            return back()->with('error', 'Retry failed. The message remains saved.');
        }

        return back()->with('success', 'Email sent on retry.');
    }
}
