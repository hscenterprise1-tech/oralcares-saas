<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\PatientFollowUp;
use App\Services\ClinicContext;
use App\Services\Patient\FollowUps;
use App\Services\Patient\Patient360\Patient360Access;
use App\Support\ClinicClock;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The clinic's follow-ups, across patients: overdue, due today, coming up,
 * done. A practitioner starts on their own; the front desk and the owner see
 * the branch's. Changes go through `FollowUps` (the one writer), as from
 * Patient 360. Read in pages, never all at once.
 */
class FollowUpBoardController extends Controller
{
    public const TABS = ['overdue', 'today', 'upcoming', 'done'];

    private const PAGE = 25;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected Patient360Access $access,
        protected FollowUps $followUps,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->access->canManageFollowUps($user), 403);
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->branchScopeOrAbort();
        $me = $this->clinicContext->currentEmployee();
        $practitioner = $me?->practitionerProfile !== null;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overdue';
        $mine = $me !== null && $request->query('mine', $practitioner ? '1' : '0') === '1';
        $today = ClinicClock::now()->toDateString();

        $base = fn () => PatientFollowUp::query()->where('clinic_id', $clinic->id)
            ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch->id))
            ->when($mine, fn (Builder $query) => $query->where('practitioner_id', $me->id));
        $scope = fn (Builder $query, string $tab) => match ($tab) {
            'overdue' => $query->where('status', PatientFollowUp::DUE)->where('due_on', '<', $today),
            'today' => $query->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->whereOnDate('due_on', $today),
            'upcoming' => $query->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->where('due_on', '>=', ClinicClock::now()->addDay()->toDateString())->where('due_on', '<', ClinicClock::now()->addDays(31)->toDateString()),
            'done' => $query->where('status', PatientFollowUp::COMPLETED)->where('completed_at', '>=', now()->subDays(30)),
        };

        return Inertia::render('ClinicPortal/FollowUps/Index', [
            'tab' => $tab,
            'mine' => $mine,
            'can_filter_mine' => $me !== null,
            'counts' => collect(['overdue', 'today'])->mapWithKeys(fn (string $key) => [$key => $scope($base(), $key)->limit(1000)->count()]),
            'followUps' => Inertia::scroll(fn () => $scope($base(), $tab)
                ->with([
                    'patient' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'first_name', 'last_name', 'uhid', 'phone'),
                    'practitioner' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'name'),
                    'patientTreatment' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'title', 'specialty_key'),
                ])
                ->orderBy('due_on', $tab === 'done' ? 'desc' : 'asc')->orderByDesc('priority')->orderBy('id')
                ->cursorPaginate(self::PAGE)
                ->through(fn (PatientFollowUp $followUp) => [
                    'id' => $followUp->id,
                    'due_on' => $followUp->due_on?->toDateString(),
                    'days_overdue' => $followUp->status === PatientFollowUp::DUE && $followUp->due_on && $followUp->due_on->toDateString() < $today
                        ? (int) $followUp->due_on->diffInDays(ClinicClock::now()->startOfDay()) : 0,
                    'status' => $followUp->status,
                    'priority' => $followUp->priorityLabel(),
                    'reason' => $followUp->reason,
                    'notes' => $followUp->notes,
                    'patient' => $followUp->patient ? ['id' => $followUp->patient->id, 'name' => trim($followUp->patient->first_name.' '.$followUp->patient->last_name), 'uhid' => $followUp->patient->uhid, 'phone' => $followUp->patient->phone] : null,
                    'practitioner' => $followUp->practitioner?->name,
                    'treatment' => $followUp->patientTreatment ? ['title' => $followUp->patientTreatment->title, 'specialty' => $followUp->patientTreatment->specialty_key] : null,
                    'links' => [
                        'patient' => route('clinic.patients.show', $followUp->patient_id, false),
                        'complete' => route('clinic.patients.follow-ups.complete', [$followUp->patient_id, $followUp->id], false),
                        'cancel' => route('clinic.patients.follow-ups.cancel', [$followUp->patient_id, $followUp->id], false),
                        'reschedule' => route('clinic.patients.follow-ups.update', [$followUp->patient_id, $followUp->id], false),
                        'annotate' => route('clinic.follow-ups.annotate', $followUp->id, false),
                        'book' => route('clinic.appointments.index', ['patient' => $followUp->patient_id], false),
                    ],
                ])),
        ]);
    }

    public function annotate(Request $request, int $followUp): RedirectResponse
    {
        abort_unless($this->access->canManageFollowUps($request->user()), 403);
        $record = PatientFollowUp::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($followUp);
        $data = $request->validate(['priority' => ['required', Rule::in(array_keys(PatientFollowUp::PRIORITIES))], 'notes' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->followUps->annotate($record, $data['priority'], $data['notes'] ?? null);
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['follow_up' => $e->getMessage()]);
        }

        return back()->with('success', 'Follow-up updated.');
    }
}
