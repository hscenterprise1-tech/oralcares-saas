<?php

namespace App\Http\Controllers\Company\Crm;

use App\ActivityType;
use App\DemoStatus;
use App\Http\Controllers\Controller;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\LeadWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Demos, which are worked from a deal rather than from a page of their own.
 *
 * The demos index is gone: a demo belongs to a deal and to a day, so it is read
 * and acted on in the deal's Evaluation zone and in My Work's Demos tab — both
 * of which now carry all four actions, including the reschedule this
 * controller's index page was once the only way to reach.
 */
class DemoController extends Controller
{
    public function __construct(protected LeadWorkspaceService $workspaceService) {}

    /**
     * A single demo, as its own page.
     *
     * A demo is a scheduled event that people forward and reschedule, so it
     * deserves a URL of its own — and authorization is per-record here rather
     * than the index's board-level viewAny check.
     */
    public function show(Demo $demo): Response
    {
        Gate::authorize('view', $demo);

        $demo->load(['lead:id,company_name,contact_name,phone,email,status', 'presenter:id,name', 'cancelledBy:id,name']);

        return Inertia::render('Company/Crm/Demos/Show', [
            'demo' => $demo,
        ]);
    }

    /**
     * Schedule a new Demo for a Lead.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Demo::class);

        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'presenter_id' => 'nullable|exists:users,id',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15|max:240',
            'demo_mode' => 'nullable|string|in:online,in_person',
            /*
             * The platform, not just online/in-person. `demo_mode` is derived
             * from it in `scheduleDemo()` so the two cannot contradict each
             * other; both are accepted so callers that only know the mode keep
             * working.
             */
            'meeting_platform' => ['nullable', Rule::in(array_keys(Demo::PLATFORMS))],
            'location' => 'nullable|string|max:255',
            'meeting_link' => 'nullable|string|max:500',
            'meeting_id' => 'nullable|string|max:255',
            'passcode' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (Gate::denies('update', $lead)) {
            abort(403, 'Unauthorized to schedule demo for this lead.');
        }

        try {
            $this->workspaceService->scheduleDemo($lead, $validated, $request->user());
        } catch (\InvalidArgumentException $e) {
            // Presenter double-booking — a correctable user error, not a fault.
            return back()->withErrors(['scheduled_at' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Demo scheduled successfully.');
    }

    /**
     * Update client confirmation status.
     */
    public function confirm(Request $request, Demo $demo)
    {
        Gate::authorize('update', $demo);

        /*
         * The status is optional and means `confirmed`.
         *
         * Every caller in the product is a button labelled "Confirm" that posts
         * no body: the demos board, the demo page and the deal's evaluation
         * zone all did, and all three failed validation every time they were
         * pressed. Requiring the field described an interface nobody had built.
         *
         * `confirmDemo()` already falls back to Confirmed for an unreadable
         * value, so this only makes the endpoint agree with the service behind
         * it — and a caller that does want to record `declined` or `no_response`
         * still can.
         */
        $validated = $request->validate([
            'confirmation_status' => 'nullable|string|in:pending,confirmed,declined,no_response',
        ]);

        try {
            $this->workspaceService->confirmDemo(
                $demo,
                $validated['confirmation_status'] ?? 'confirmed',
                $request->user()
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['confirmation_status' => $e->getMessage()]);
        }

        return back()->with('success', 'Demo confirmation status updated.');
    }

    /**
     * Reschedule a Demo.
     */
    public function reschedule(Request $request, Demo $demo)
    {
        Gate::authorize('update', $demo);

        $validated = $request->validate([
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15|max:240',
            'demo_mode' => 'nullable|string|in:online,in_person',
            'location' => 'nullable|string|max:255',
            'meeting_link' => 'nullable|string|max:500',
            'presenter_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $this->workspaceService->rescheduleDemo($demo, $validated, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['scheduled_at' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Demo rescheduled successfully.');
    }

    /**
     * Complete a Demo and record outcome & feedback.
     */
    public function complete(Request $request, Demo $demo)
    {
        Gate::authorize('update', $demo);

        $validated = $request->validate([
            'outcome' => 'required|string|in:interested,highly_interested,needs_followup,trial_requested,quotation_requested,not_interested,no_decision,lost',
            'feedback' => 'nullable|string|max:2000',
            'objections' => 'nullable|string|max:2000',
            'next_action' => 'nullable|string|max:500',
            'next_followup_at' => 'nullable|date',
        ]);

        try {
            $this->workspaceService->completeDemo($demo, $validated, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['outcome' => $e->getMessage()]);
        }

        return back()->with('success', 'Demo completed successfully.');
    }

    /**
     * Mark Demo as No-Show.
     */
    public function noShow(Request $request, Demo $demo)
    {
        Gate::authorize('update', $demo);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $this->workspaceService->markDemoNoShow($demo, $validated, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['notes' => $e->getMessage()]);
        }

        return back()->with('success', 'Demo marked as No-Show.');
    }

    /**
     * Cancel a Demo.
     */
    public function cancel(Request $request, Demo $demo)
    {
        Gate::authorize('update', $demo);

        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:1000',
        ]);

        try {
            $this->workspaceService->cancelDemo($demo, $validated['cancellation_reason'], $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['cancellation_reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Demo cancelled.');
    }

    /**
     * Update basic Demo details.
     */
    public function update(Request $request, Demo $demo)
    {
        Gate::authorize('update', $demo);

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(DemoStatus::class)],
            'meeting_link' => 'nullable|string',
            'notes' => 'nullable|string',
            'feedback' => 'nullable|string',
        ]);

        /*
         * The dedicated confirm/complete/reschedule/no-show/cancel actions
         * each enforce their own legality — `cancelDemo()`'s terminal check
         * is the one every sibling transition is missing. This generic
         * update wrote `status` straight through with none of them, so a
         * plain PATCH could resurrect an already-completed or cancelled
         * demo. Held to the same rule whenever a status change rides along.
         */
        if (isset($validated['status']) && $validated['status'] !== $demo->status->value) {
            $terminal = [DemoStatus::Completed, DemoStatus::NoShow, DemoStatus::Cancelled, DemoStatus::Rescheduled];

            if (in_array($demo->status, $terminal, true)) {
                throw ValidationException::withMessages([
                    'status' => "This demo is already {$demo->status->label()} and cannot change state.",
                ]);
            }
        }

        $demo->update(array_filter($validated));

        if (($validated['status'] ?? null) === 'completed' && $demo->lead) {
            $demo->lead->activities()->create([
                'user_id' => $request->user()?->id,
                'type' => ActivityType::Demo,
                'description' => 'Demo completed.'.(! empty($validated['feedback']) ? "\nFeedback: {$validated['feedback']}" : ''),
                'properties' => [
                    'demo_id' => $demo->id,
                    'feedback' => $validated['feedback'] ?? null,
                ],
            ]);
        }

        return back()->with('success', 'Demo updated.');
    }

    /**
     * Delete Demo record.
     */
    public function destroy(Demo $demo)
    {
        Gate::authorize('delete', $demo);
        $demo->delete();

        return back()->with('success', 'Demo deleted successfully.');
    }
}
