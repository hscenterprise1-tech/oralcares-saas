<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\OnboardingProgress;
use App\Models\OnboardingSession;
use App\Models\OnboardingTemplateStep;
use App\Models\StaffDocumentType;
use App\OnboardingSessionStatus;
use App\OwnerInvitationStatus;
use App\Services\ClinicContext;
use App\Services\Onboarding\OnboardingEngine;
use App\Services\Onboarding\OnboardingGate;
use App\Services\Onboarding\OwnerInvitationService;
use App\Services\Security\Malware\UploadRejectedException;
use App\Services\Staff\StaffDocumentChecklist;
use App\Services\Staff\StaffDocumentService;
use App\Support\ClinicFrontend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clinic onboarding.
 *
 * A clinic provisioned through CRM has an onboarding session and gets the
 * wizard: account setup, then the template's sections, resumable and
 * auto-saved. The session, the step and the clinic all come from the
 * signed-in member's context, never from the request.
 *
 * Clinics provisioned before the engine keep the legacy checklist
 * (`onboarding_progress`), handled by update()/complete() and the legacy
 * branch of index().
 */
class OnboardingController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected OnboardingEngine $engine,
        protected OnboardingGate $gate,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $clinic = $this->clinicContext->clinic();
        $user = $request->user();

        if ($this->gate->governs($clinic)) {
            $session = $this->gate->pendingFor($user, $clinic, $this->clinicContext->currentEmployee());

            if (! $session) {
                return redirect()->route('dashboard');
            }

            if ($session->kind === OnboardingSession::KIND_CLINIC && $this->gate->needsAccountSetup($user, $clinic)) {
                return redirect()->route('clinic.onboarding.account');
            }

            $canEdit = $this->gate->canEdit($user, $session, $this->clinicContext->currentEmployee());
            // A paused session waits on its own screen until the owner resumes it.
            $mode = ! $canEdit ? 'waiting' : ($session->status === OnboardingSessionStatus::Paused ? 'paused' : 'edit');

            return Inertia::render('ClinicPortal/Onboarding/Wizard', [
                'mode' => $mode,
                ...($mode === 'waiting'
                    ? ['session' => ['kind' => $session->kind, 'status' => $session->status->value]]
                    : $this->engine->view($session, $user, $request->string('step')->toString() ?: null)),
                'clinicName' => $clinic->branding_name,
                'links' => $this->links(),
            ]);
        }

        return $this->legacyIndex($request);
    }

    /**
     * Account setup: the owner confirms their own name and mobile number
     * right after accepting the invitation.
     */
    public function account(Request $request, OwnerInvitationService $invitations): Response|RedirectResponse
    {
        $invitation = $invitations->forOwner($this->clinicContext->clinic()->id, $request->user()->id);

        if (! $invitation) {
            return redirect()->route('clinic.onboarding.index');
        }

        return Inertia::render('ClinicPortal/Onboarding/Account', [
            'clinicName' => $this->clinicContext->clinic()->branding_name,
            'account' => ['name' => $request->user()->name, 'email' => $request->user()->email, 'phone' => (string) $request->user()->phone],
            'links' => $this->links(),
        ]);
    }

    public function saveAccount(Request $request, OwnerInvitationService $invitations): RedirectResponse
    {
        $invitation = $invitations->forOwner($this->clinicContext->clinic()->id, $request->user()->id);
        abort_unless($invitation, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s]{7,20}$/'],
        ]);

        $request->user()->update($validated);
        $invitations->advance($invitation, OwnerInvitationStatus::ProfileCompleted);

        return redirect()->route('clinic.onboarding.index');
    }

    public function draft(Request $request, string $step): JsonResponse
    {
        $session = $this->editableSession($request);
        $validated = $request->validate(['values' => ['present', 'array']]);

        $saved = $this->engine->saveDraft($session, $step, $validated['values'], $request->user());

        return response()->json(['saved_at' => $saved->draft_saved_at?->toIso8601String(), 'status' => $saved->status->value]);
    }

    public function submit(Request $request, string $step): RedirectResponse
    {
        $session = $this->editableSession($request);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
            'values' => ['present', 'array'],
        ]);

        $result = $this->engine->submit($session, $step, $validated['values'], $validated['idempotency_key'], $request->user());

        if ($result['completed']) {
            return redirect()->route('clinic.onboarding.done');
        }

        return redirect()->route('clinic.onboarding.index', ['step' => $result['next']])
            ->with('success', $result['replayed'] ? 'Already saved.' : 'Saved.');
    }

    public function skip(Request $request, string $step): RedirectResponse
    {
        $session = $this->editableSession($request);
        $this->engine->skip($session, $step, $request->user());

        return redirect()->route('clinic.onboarding.index', ['step' => $session->fresh()->current_step]);
    }

    public function pause(Request $request): RedirectResponse
    {
        $this->engine->pause($this->editableSession($request), $request->user());

        return redirect()->route('clinic.onboarding.index');
    }

    public function resume(Request $request): RedirectResponse
    {
        $this->engine->resume($this->editableSession($request), $request->user());

        return redirect()->route('clinic.onboarding.index');
    }

    /**
     * A staff member uploads one of their own documents during onboarding.
     */
    public function uploadDocument(Request $request, StaffDocumentChecklist $checklist, StaffDocumentService $documents): RedirectResponse
    {
        $session = $this->editableSession($request);
        abort_unless($session->kind === OnboardingSession::KIND_STAFF, 404);

        $clinic = $this->clinicContext->clinic();
        $employee = $this->clinicContext->currentEmployee();
        $checklist->ensureCatalogue($clinic);

        $validated = $request->validate([
            'document_type_id' => ['required', 'integer', Rule::exists('staff_document_types', 'id')->where('clinic_id', $clinic->id)->where('is_active', true)],
            'file' => ['required', 'file', 'mimes:'.implode(',', StaffDocumentService::MIMES), 'max:'.StaffDocumentService::MAX_KB],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ]);

        $type = StaffDocumentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($validated['document_type_id']);

        try {
            $documents->upload($clinic, $employee, $type, $request->file('file'), $validated, $request->user());
        } catch (UploadRejectedException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', "{$type->name} uploaded.");
    }

    /**
     * Completion: shown once, right after the final step.
     */
    public function done(Request $request): Response|RedirectResponse
    {
        $clinic = $this->clinicContext->clinic();
        $employee = $this->clinicContext->currentEmployee();
        $session = $this->engine->clinicSession($clinic);
        $staff = $employee ? $this->engine->staffSession($employee) : null;
        $finished = $staff?->isCompleted() ? $staff : ($session?->isCompleted() ? $session : null);

        if (! $finished) {
            return redirect()->route('clinic.onboarding.index');
        }

        return Inertia::render('ClinicPortal/Onboarding/Done', [
            'kind' => $finished->kind,
            'clinicName' => $clinic->branding_name,
            'completedAt' => $finished->completed_at?->toIso8601String(),
            // Staff management is offered only once its page is built (`ClinicFrontend`).
            'links' => ['dashboard' => route('dashboard'), 'staff' => $request->user()->hasClinicPermission(ClinicPermission::StaffManage) && ClinicFrontend::available('admin.employees.index') ? route('admin.employees.index') : null],
        ]);
    }

    // ─── Legacy checklist ───────────────────────────────────

    /**
     * Mark one legacy checklist step as done.
     */
    public function update(Request $request): RedirectResponse
    {
        $progress = $this->pendingProgress($request);

        $validated = $request->validate([
            'step' => ['required', 'string', Rule::in($progress->steps()->pluck('step_key')->all())],
        ]);

        DB::transaction(function () use ($progress, $validated) {
            $progress = OnboardingProgress::query()->lockForUpdate()->findOrFail($progress->id);

            $done = array_values(array_unique([...($progress->completed_steps ?? []), $validated['step']]));
            $next = $progress->steps()->first(fn (OnboardingTemplateStep $step) => ! in_array($step->step_key, $done, true));

            $progress->update([
                'completed_steps' => $done,
                'current_step' => $next?->step_key ?? $validated['step'],
                'status' => 'in_progress',
            ]);
        });

        return back()->with('success', 'Step marked as done.');
    }

    /**
     * Finish the legacy checklist once every required step is done.
     */
    public function complete(Request $request): RedirectResponse
    {
        $progress = $this->pendingProgress($request);

        if (! $progress->hasCompletedRequiredSteps()) {
            throw ValidationException::withMessages([
                'step' => 'Finish every required step before completing setup.',
            ]);
        }

        $progress->update(['status' => 'completed', 'current_step' => 'completed']);

        return redirect()->route('dashboard');
    }

    private function legacyIndex(Request $request): Response|RedirectResponse
    {
        $progress = OnboardingProgress::forClinicUser($request->user(), $this->clinicContext->clinic());

        if (! $progress || $progress->status === 'completed') {
            return redirect()->route('dashboard');
        }

        $done = $progress->completed_steps ?? [];

        return Inertia::render('Clinic/Onboarding/Index', [
            'clinicName' => $this->clinicContext->clinic()?->name,
            'status' => $progress->status,
            'currentStep' => $progress->current_step,
            'steps' => $progress->steps()->values()->map(fn (OnboardingTemplateStep $step) => [
                'key' => $step->step_key,
                'label' => $step->label,
                'description' => $step->description,
                'required' => (bool) $step->is_required,
                'done' => in_array($step->step_key, $done, true),
            ]),
            'canFinish' => $progress->hasCompletedRequiredSteps(),
            'canManage' => $request->user()->hasClinicPermission(ClinicPermission::SettingsManage),
        ]);
    }

    private function pendingProgress(Request $request): OnboardingProgress
    {
        $progress = OnboardingProgress::forClinicUser($request->user(), $this->clinicContext->clinic());

        abort_if(! $progress || $progress->status === 'completed', 404);

        return $progress;
    }

    /**
     * The session the signed-in member may change. 403 when they may only
     * watch (staff waiting for the clinic's setup).
     */
    private function editableSession(Request $request): OnboardingSession
    {
        $clinic = $this->clinicContext->clinic();
        $employee = $this->clinicContext->currentEmployee();
        $session = $this->gate->pendingFor($request->user(), $clinic, $employee);

        abort_unless($session, 404);
        abort_unless($this->gate->canEdit($request->user(), $session, $employee), 403);

        return $session;
    }

    /**
     * @return array<string, string>
     */
    private function links(): array
    {
        return [
            'index' => route('clinic.onboarding.index'),
            'account' => route('clinic.onboarding.account'),
            'draft' => route('clinic.onboarding.draft', ['step' => '__STEP__']),
            'submit' => route('clinic.onboarding.submit', ['step' => '__STEP__']),
            'skip' => route('clinic.onboarding.skip', ['step' => '__STEP__']),
            'pause' => route('clinic.onboarding.pause'),
            'resume' => route('clinic.onboarding.resume'),
            'logout' => route('clinic.logout'),
        ];
    }
}
