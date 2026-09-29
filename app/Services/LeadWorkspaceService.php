<?php

namespace App\Services;

use App\ActivityType;
use App\BillingCycle;
use App\ContactRole;
use App\DemoConfirmationStatus;
use App\DemoOutcome;
use App\DemoStatus;
use App\Exceptions\IllegalStageTransition;
use App\HealthcareSpecialty;
use App\LeadPriority;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\CommercialInvoice;
use App\Models\CompanyEmployee;
use App\Models\CompanyPackageAddon;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\CompanySubscription;
use App\Models\Contact;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadCallLog;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Team;
use App\Models\Trial;
use App\Models\User;
use App\Observers\CRM\LeadObserver;
use App\OrganizationType;
use App\QuotationStatus;
use App\Services\CRM\DealActivityRecorder;
use App\Services\CRM\DealConversationService;
use App\Services\CRM\DealStageService;
use App\Services\CRM\GstCalculationService;
use App\Services\CRM\LeadFollowupSyncService;
use App\Services\CRM\QuotationStateMachine;
use App\Services\CRM\TrialLifecycleService;
use App\Services\Marketing\MarketingSettingsService;
use App\Services\Marketing\PhoneNormalizer;
use App\Services\Marketing\WhatsAppMessageService;
use App\Shared\Notification\DTO\NotificationActionDTO;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationCategory;
use App\Shared\Notification\Enums\NotificationPriority;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Services\NotificationEngineService;
use App\Support\CrmScope;
use App\TaskPriority;
use App\TaskStatus;
use App\TaskType;
use App\TrialOutcome;
use App\TrialStatus;
use App\UserType;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LeadWorkspaceService
{
    /**
     * Upper bound on any single list returned to the personal workspace.
     *
     * A rep's own book is normally well under this, but the queues were
     * previously unbounded, so one heavily-loaded account was enough to ship
     * thousands of rows into the Inertia payload.
     */
    public const WORKSPACE_LIST_LIMIT = 100;

    /**
     * Upper bound on kanban cards shipped to the client in one payload.
     *
     * The board renders every card, so this is a payload-size guard rather
     * than a query-cost one. Column totals are still counted across the full
     * result set, so the numbers on screen remain accurate.
     */
    public const PIPELINE_CARD_LIMIT = 300;

    public function __construct(
        protected LeadAiCopilotService $copilotService,
        protected DealActivityRecorder $activity,
        protected LeadFollowupSyncService $followupSync,
    ) {}

    public function getWorkspacePayload(array $filters = [], ?int $activeLeadId = null, ?User $user = null): array
    {
        return $this->getSalesOsPayload($filters, $activeLeadId, $user);
    }

    /**
     * Build the canonical lookups (sources, organization types, assignable
     * users) needed by the standalone "Add Lead" form.
     */
    public function getLeadCreatePayload(?User $user = null): array
    {
        $orgId = $user ? $user->resolveOrganizationId() : null;
        $isMasterAdmin = $user ? $user->isMasterAdmin() : true;

        $assignableUsers = User::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->where('user_type', '!=', UserType::ClinicUser)
            ->select('id', 'name', 'email')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            ->values()
            ->toArray();

        return [
            'leadSources' => Lead::sources(),
            'organizationTypes' => $this->organizationTypeOptions(),
            'assignableUsers' => $assignableUsers,

            /*
             * Everything the form needs to offer a choice rather than a free
             * text box. Specialties come from HealthcareSpecialty — the CRM's
             * own vocabulary — rather than ClinicType, which describes what an
             * organization is and is used by the clinic product.
             */
            'specialties' => HealthcareSpecialty::options(),
            'decisionRoles' => collect(ContactRole::cases())
                ->map(fn (ContactRole $role) => ['value' => $role->value, 'label' => $role->value])
                ->values()
                ->all(),

            /*
             * Teams reads `teams`, which is what `leads.assigned_team_id`
             * points at. It is empty until sales teams exist, and an empty
             * list is the honest answer rather than offering department rows
             * no deal has ever referenced.
             */
            'assignableTeams' => Team::query()
                ->when($user?->resolveOrganizationId(), fn ($q, $id) => $q->where('organization_id', $id))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name])
                ->values()
                ->all(),

            'packages' => CompanyPlan::query()
                ->where('is_active', true)
                ->orderBy('price')
                ->get(['id', 'name', 'price', 'billing_period'])
                ->map(fn (CompanyPlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'price' => (float) $plan->price,
                    'billing_period' => $plan->billing_period,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Canonical organization-type dropdown options, shared by every payload
     * builder that needs to render an organization-type selector.
     *
     * @return array<string, string>
     */
    protected function organizationTypeOptions(): array
    {
        return collect(OrganizationType::cases())
            ->mapWithKeys(fn (OrganizationType $type) => [$type->value => Str::headline($type->value)])
            ->toArray();
    }

    /**
     * Build the high-performance, paginated Leads Directory payload with server-side
     * filtering, sorting, real KPI metrics, and explicit frontend DTO contracts.
     */
    public function getLeadsDirectoryPayload(array $filters = [], ?User $user = null, int $perPage = 25): array
    {
        $orgId = $user ? $user->resolveOrganizationId() : null;
        $isMasterAdmin = $user ? $user->isMasterAdmin() : true;

        // 1. Base query from the single authoritative scope.
        //
        // This used to filter on resolveOrganizationId() directly, which gave
        // a rep the whole organization's book on this list while the Overview
        // — which has always used CrmScope — showed only their own.
        $baseQuery = CrmScope::leads($user);

        // 2. Real Database KPI Metrics
        $totalLeads = (clone $baseQuery)->count();
        $activeQuery = (clone $baseQuery)->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost]);
        $activeLeads = (clone $activeQuery)->count();
        $pipelineMrr = (float) (clone $activeQuery)->sum('expected_mrr');
        $pipelineArr = (float) (clone $activeQuery)->sum(DB::raw('COALESCE(expected_arr, expected_mrr * 12, 0)'));
        /*
         * One definition of hot.
         *
         * This read `priority = high OR priority = 'hot' OR manual_priority =
         * 'Hot'` — three spellings of the same idea, two of which no form
         * could set. `manual_priority` has been merged into `priority`, and
         * 'hot' was never a `LeadPriority` value.
         */
        $hotLeads = (clone $activeQuery)->where('priority', LeadPriority::High)->count();
        $overdueFollowups = (clone $activeQuery)
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<', now())
            ->count();
        $unassignedLeads = (clone $activeQuery)
            ->whereNull('assigned_user_id')
            ->count();

        $metrics = [
            'total_leads' => $totalLeads,
            'active_leads' => $activeLeads,
            'pipeline_mrr' => $pipelineMrr,
            'pipeline_arr' => $pipelineArr,
            'hot_leads' => $hotLeads,
            'overdue_followups' => $overdueFollowups,
            'unassigned_leads' => $unassignedLeads,
        ];

        // 3. Stage Counts for Lifecycle Filter Pills
        $stageCounts = [
            'all' => $totalLeads,
            'new' => (clone $baseQuery)->where('status', LeadStatus::New)->count(),
            'qualified' => (clone $baseQuery)->whereIn('status', [LeadStatus::Contacted, LeadStatus::Assigned, 'qualified'])->count(),
            'demo' => (clone $baseQuery)->whereIn('status', [LeadStatus::DemoScheduled, LeadStatus::DemoCompleted])->count(),
            'trial' => (clone $baseQuery)->whereIn('status', [LeadStatus::TrialActive, 'trial_active', 'trial'])->count(),
            'quotation' => (clone $baseQuery)->whereIn('status', [LeadStatus::QuotationSent, LeadStatus::QuotationApproved, LeadStatus::Negotiation, LeadStatus::PaymentReceived, 'quotation'])->count(),
            'won' => (clone $baseQuery)->where('status', LeadStatus::Won)->count(),
            'lost' => (clone $baseQuery)->where('status', LeadStatus::Lost)->count(),
        ];

        // 4. Build Filtered Query
        $filteredQuery = clone $baseQuery;

        // Search Filter
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $filteredQuery->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%")
                    ->orWhereHas('assignedUser', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Stage Filter
        if (! empty($filters['stage']) && $filters['stage'] !== 'all') {
            $stage = $filters['stage'];
            if ($stage === 'new') {
                $filteredQuery->where('status', LeadStatus::New);
            } elseif ($stage === 'qualified') {
                $filteredQuery->whereIn('status', [LeadStatus::Contacted, LeadStatus::Assigned, 'qualified']);
            } elseif ($stage === 'demo' || $stage === 'demo_scheduled' || $stage === 'demo_completed') {
                $filteredQuery->whereIn('status', [LeadStatus::DemoScheduled, LeadStatus::DemoCompleted]);
            } elseif ($stage === 'trial' || $stage === 'trial_active' || $stage === 'trial_started') {
                $filteredQuery->whereIn('status', [LeadStatus::TrialActive, 'trial_active', 'trial']);
            } elseif ($stage === 'quotation' || $stage === 'quotation_sent') {
                $filteredQuery->whereIn('status', [LeadStatus::QuotationSent, LeadStatus::QuotationApproved, LeadStatus::Negotiation, LeadStatus::PaymentReceived, 'quotation']);
            } elseif ($stage === 'won') {
                $filteredQuery->where('status', LeadStatus::Won);
            } elseif ($stage === 'lost') {
                $filteredQuery->where('status', LeadStatus::Lost);
            } else {
                $filteredQuery->where('status', $stage);
            }
        }

        // Source Filter
        if (! empty($filters['source']) && $filters['source'] !== 'all') {
            $filteredQuery->where('source', $filters['source']);
        }

        // Assigned Rep Filter
        if (! empty($filters['assigned_user_id']) && $filters['assigned_user_id'] !== 'all') {
            if ($filters['assigned_user_id'] === 'unassigned') {
                $filteredQuery->whereNull('assigned_user_id');
            } else {
                $filteredQuery->where('assigned_user_id', (int) $filters['assigned_user_id']);
            }
        }

        // Priority Filter
        if (! empty($filters['priority']) && $filters['priority'] !== 'all') {
            $filteredQuery->where('priority', $filters['priority']);
        }

        // Saved Cuts — the queries behind the headline figures. Each branch is
        // the metric's own predicate, so a card and its list cannot disagree.
        if (! empty($filters['cut'])) {
            $filteredQuery->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost]);

            if ($filters['cut'] === 'hot') {
                $filteredQuery->where('priority', LeadPriority::High);
            } elseif ($filters['cut'] === 'overdue') {
                $filteredQuery->whereNotNull('next_followup_at')
                    ->where('next_followup_at', '<', now());
            } elseif ($filters['cut'] === 'unassigned') {
                $filteredQuery->whereNull('assigned_user_id');
            }
        }

        // Organization Type Filter
        if (! empty($filters['organization_type']) && $filters['organization_type'] !== 'all') {
            $filteredQuery->where('organization_type', $filters['organization_type']);
        }

        // City Filter
        if (! empty($filters['city']) && $filters['city'] !== 'all') {
            $city = $filters['city'];
            $filteredQuery->where(function ($q) use ($city) {
                $q->whereHas('organization', fn ($oq) => $oq->where('city', $city))
                    ->orWhereHas('marketingContact', fn ($mq) => $mq->where('city', $city));
            });
        }

        // State Filter
        if (! empty($filters['state']) && $filters['state'] !== 'all') {
            $state = $filters['state'];
            $filteredQuery->whereHas('organization', fn ($oq) => $oq->where('state', $state));
        }

        // 5. Server-side Sorting
        $sortField = $filters['sort'] ?? 'updated_at';
        $sortDirection = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSorts = [
            'company_name' => 'company_name',
            'contact_name' => 'contact_name',
            'status' => 'status',
            'priority' => 'priority',
            'expected_mrr' => 'expected_mrr',
            'expected_arr' => 'expected_arr',
            'lead_score' => 'lead_score',
            'next_followup_at' => 'next_followup_at',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];

        $columnToSort = $allowedSorts[$sortField] ?? 'updated_at';
        $filteredQuery->orderBy($columnToSort, $sortDirection);

        // 6. Selective Eager Loading & Length-Aware Pagination
        $paginator = $filteredQuery
            ->with([
                'assignedUser:id,name,email',
                'organization:id,name,city,state',
                'marketingContact:id,city',
            ])
            ->paginate($perPage)
            ->withQueryString();

        // 7. Transform to Explicit Frontend DTO
        $transformedData = collect($paginator->items())->map(function (Lead $lead) {
            $statusVal = $lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status;
            $statusLabel = $lead->status instanceof LeadStatus ? $lead->status->label() : ucfirst(str_replace('_', ' ', (string) $lead->status));
            $priorityVal = $lead->priority instanceof \BackedEnum ? $lead->priority->value : (string) ($lead->priority ?? 'warm');

            return [
                'id' => $lead->id,
                'company_name' => $lead->company_name,
                'slug' => $lead->slug,
                'contact_name' => $lead->contact_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'address' => $lead->address,
                'city' => $lead->city ?? $lead->organization?->city ?? $lead->marketingContact?->city ?? null,
                'state' => $lead->state ?? $lead->organization?->state ?? null,
                'has_complete_address' => $lead->has_complete_address,
                'status' => $statusVal,
                'status_label' => $statusLabel,
                'priority' => $priorityVal,
                'source' => $lead->source ?? 'manual_entry',
                'source_label' => Lead::sources()[$lead->source] ?? ucfirst(str_replace('_', ' ', $lead->source ?? 'manual_entry')),
                'organization_type' => $lead->organization_type instanceof \BackedEnum ? $lead->organization_type->value : ((string) $lead->organization_type ?: null),
                'branch_count' => (int) ($lead->branch_count ?? 1),
                'expected_mrr' => (float) ($lead->expected_mrr ?? 0),
                'expected_arr' => (float) ($lead->expected_arr ?: ($lead->expected_mrr ? $lead->expected_mrr * 12 : 0)),
                'lead_score' => (int) ($lead->lead_score ?? $lead->automated_score ?? 0),
                'next_best_action' => $lead->next_best_action,
                'next_followup_at' => $lead->next_followup_at ? $lead->next_followup_at->toISOString() : null,
                'next_followup_formatted' => $lead->next_followup_at ? $lead->next_followup_at->format('d M Y, h:i A') : null,
                'is_followup_overdue' => $lead->next_followup_at ? $lead->next_followup_at->isPast() : false,
                'followup_notes' => $lead->followup_notes,
                'assigned_user' => $lead->assignedUser ? [
                    'id' => $lead->assignedUser->id,
                    'name' => $lead->assignedUser->name,
                    'email' => $lead->assignedUser->email,
                    'initials' => collect(explode(' ', $lead->assignedUser->name))->map(fn ($n) => $n[0] ?? '')->take(2)->join(''),
                ] : null,
                'created_at' => $lead->created_at ? $lead->created_at->format('d M Y') : null,
                'updated_at' => $lead->updated_at ? $lead->updated_at->diffForHumans() : null,
                'lost_reason' => $lead->lost_reason instanceof \BackedEnum ? $lead->lost_reason->value : (string) $lead->lost_reason,
                'lost_at' => $lead->lost_at ? $lead->lost_at->format('d M Y') : null,
                'winback_scheduled_at' => $lead->winback_scheduled_at ? $lead->winback_scheduled_at->format('d M Y') : null,
            ];
        })->values()->toArray();

        // 8. Assignable CRM Users
        $assignableUsers = User::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->where('user_type', '!=', UserType::ClinicUser)
            ->select('id', 'name', 'email')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'initials' => collect(explode(' ', $u->name))->map(fn ($n) => $n[0] ?? '')->take(2)->join(''),
            ])
            ->values()
            ->toArray();

        $availableCities = Organization::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('id', $orgId))
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->pluck('city')
            ->merge(
                MarketingContact::query()
                    ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->distinct()
                    ->pluck('city')
            )
            ->merge(
                Lead::query()
                    ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->distinct()
                    ->pluck('city')
            )
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        $availableStates = Organization::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('id', $orgId))
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->distinct()
            ->pluck('state')
            ->merge(
                Lead::query()
                    ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
                    ->whereNotNull('state')
                    ->where('state', '!=', '')
                    ->distinct()
                    ->pluck('state')
            )
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        return [
            'leads' => [
                'data' => $transformedData,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'links' => $paginator->linkCollection()->toArray(),
            ],
            'leadsList' => $transformedData,
            'metrics' => $metrics,
            'stageCounts' => $stageCounts,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'stage' => $filters['stage'] ?? 'all',
                'source' => $filters['source'] ?? 'all',
                'assigned_user_id' => $filters['assigned_user_id'] ?? 'all',
                'priority' => $filters['priority'] ?? 'all',
                'cut' => $filters['cut'] ?? null,
                'organization_type' => $filters['organization_type'] ?? 'all',
                'city' => $filters['city'] ?? 'all',
                'state' => $filters['state'] ?? 'all',
                'sort' => $sortField,
                'direction' => $sortDirection,
                'per_page' => $perPage,
            ],
            'assignableUsers' => $assignableUsers,
            'leadSources' => Lead::sources(),
            'organizationTypes' => $this->organizationTypeOptions(),
            'priorities' => [
                'hot' => 'Hot',
                'warm' => 'Warm',
                'cold' => 'Cold',
                'blocked' => 'Blocked',
            ],
            'availableCities' => $availableCities,
            'availableStates' => $availableStates,
        ];
    }

    /**
     * Stream CSV of filtered leads for enterprise export.
     */
    public function exportLeadsCsv(array $filters = [], ?User $user = null): StreamedResponse
    {
        $orgId = $user ? $user->resolveOrganizationId() : null;
        $isMasterAdmin = $user ? $user->isMasterAdmin() : true;

        $query = Lead::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['assignedUser:id,name,email']);

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['stage']) && $filters['stage'] !== 'all') {
            $query->where('status', $filters['stage']);
        }

        if (! empty($filters['source']) && $filters['source'] !== 'all') {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['assigned_user_id']) && $filters['assigned_user_id'] !== 'all') {
            $query->where('assigned_user_id', (int) $filters['assigned_user_id']);
        }

        if (! empty($filters['priority']) && $filters['priority'] !== 'all') {
            $query->where('priority', $filters['priority']);
        }

        $filename = 'crm_leads_export_'.now()->format('Y_m_d_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'ID',
                'Company Name',
                'Contact Person',
                'Email',
                'Phone',
                'Stage',
                'Priority',
                'Source',
                'Expected MRR (INR)',
                'Expected ARR (INR)',
                'Assigned Rep',
                'Next Follow-up',
                'Created At',
            ]);

            $query->chunk(200, function ($leads) use ($handle) {
                foreach ($leads as $lead) {
                    fputcsv($handle, [
                        $lead->id,
                        $lead->company_name,
                        $lead->contact_name,
                        $lead->email ?? '',
                        $lead->phone ?? '',
                        $lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status,
                        $lead->priority instanceof \BackedEnum ? $lead->priority->value : (string) $lead->priority,
                        $lead->source ?? '',
                        $lead->expected_mrr ?? 0,
                        $lead->expected_arr ?: ($lead->expected_mrr ? $lead->expected_mrr * 12 : 0),
                        $lead->assignedUser?->name ?? 'Unassigned',
                        $lead->next_followup_at ? $lead->next_followup_at->format('Y-m-d H:i') : '',
                        $lead->created_at ? $lead->created_at->format('Y-m-d') : '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Build the purely actionable personal CRM workstation payload for the authenticated user.
     * Contains only personal tasks, follow-ups, demos, active trials, quotations, unscheduled leads, and assigned leads.
     * Excludes all greeting, AI briefing, organization summaries, and dashboard-style metrics.
     */
    public function getMyWorkspacePayload(User $user, array $filters = []): array
    {
        $today = Carbon::today();
        $orgId = $user->resolveOrganizationId();
        $isMasterAdmin = $user->isMasterAdmin();

        // 1. My Leads Base Query (Tenant Isolated & Assigned to User)
        $myLeadsBaseQuery = Lead::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->where('assigned_user_id', $user->id);

        $myLeadIds = (clone $myLeadsBaseQuery)->pluck('id');
        $myActiveLeadsQuery = (clone $myLeadsBaseQuery)->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost]);

        // 2. Personal Follow-ups Queue (Overdue, Due Today, Upcoming)
        $overdueLeads = (clone $myActiveLeadsQuery)
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<', $today->startOfDay())
            ->orderBy('next_followup_at')
            ->limit(self::WORKSPACE_LIST_LIMIT)
            ->get();

        $dueTodayLeads = (clone $myActiveLeadsQuery)
            ->whereNotNull('next_followup_at')
            ->whereDate('next_followup_at', $today)
            ->orderBy('next_followup_at')
            ->limit(self::WORKSPACE_LIST_LIMIT)
            ->get();

        $upcomingLeads = (clone $myActiveLeadsQuery)
            ->whereNotNull('next_followup_at')
            ->whereDate('next_followup_at', '>', $today)
            ->whereDate('next_followup_at', '<=', $today->copy()->addDays(7))
            ->orderBy('next_followup_at')
            ->limit(self::WORKSPACE_LIST_LIMIT)
            ->get();

        $followups = [
            'overdue' => $overdueLeads->map(fn ($l) => [
                'id' => $l->id,
                'lead_id' => $l->id,
                'company_name' => $l->company_name,
                'contact_name' => $l->contact_name,
                'city' => $l->city,
                'phone' => $l->phone,
                'stage' => $l->status->label(),
                'stage_key' => $l->status->value,
                'priority' => $l->priority?->value ?? 'medium',
                'due_date' => $l->next_followup_at ? $l->next_followup_at->format('d M Y') : 'Overdue',
                'time_ago' => $l->next_followup_at ? $l->next_followup_at->diffForHumans() : 'Overdue',
                'notes' => $l->followup_notes ?? 'Scheduled follow-up pending action.',
            ])->values()->toArray(),

            'due_today' => $dueTodayLeads->map(fn ($l) => [
                'id' => $l->id,
                'lead_id' => $l->id,
                'company_name' => $l->company_name,
                'contact_name' => $l->contact_name,
                'city' => $l->city,
                'phone' => $l->phone,
                'stage' => $l->status->label(),
                'stage_key' => $l->status->value,
                'priority' => $l->priority?->value ?? 'medium',
                'due_date' => 'Today',
                'time_ago' => 'Due Today',
                'notes' => $l->followup_notes ?? 'Scheduled follow-up for today.',
            ])->values()->toArray(),

            'upcoming' => $upcomingLeads->map(fn ($l) => [
                'id' => $l->id,
                'lead_id' => $l->id,
                'company_name' => $l->company_name,
                'contact_name' => $l->contact_name,
                'city' => $l->city,
                'phone' => $l->phone,
                'stage' => $l->status->label(),
                'stage_key' => $l->status->value,
                'priority' => $l->priority?->value ?? 'medium',
                'due_date' => $l->next_followup_at ? $l->next_followup_at->format('d M Y') : '',
                'time_ago' => $l->next_followup_at ? $l->next_followup_at->diffForHumans() : '',
                'notes' => $l->followup_notes ?? 'Upcoming follow-up.',
            ])->values()->toArray(),
        ];

        // 3. My Demos (Today & Upcoming)
        $myDemos = Demo::query()
            ->where(function ($q) use ($user, $myLeadIds) {
                $q->where('presenter_id', $user->id)
                    ->orWhereIn('lead_id', $myLeadIds);
            })
            ->whereIn('status', [DemoStatus::Scheduled, DemoStatus::Rescheduled])
            ->with(['lead:id,company_name,contact_name,phone,status', 'presenter:id,name'])
            ->orderBy('scheduled_at')
            ->limit(self::WORKSPACE_LIST_LIMIT)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'lead_id' => $d->lead_id,
                'company_name' => $d->lead?->company_name ?? 'Lead Demo',
                'contact_name' => $d->lead?->contact_name ?? '',
                'phone' => $d->lead?->phone ?? '',
                'presenter' => $d->presenter?->name,
                'scheduled_at' => $d->scheduled_at ? $d->scheduled_at->format('d M Y, h:i A') : 'TBD',
                'is_today' => $d->scheduled_at ? $d->scheduled_at->isToday() : false,
                'demo_mode' => $d->demo_mode ?? 'online',
                /*
                 * The platform, so My Work can separate a call from a visit.
                 * Driving to a clinic and joining a Meet are different halves of
                 * a rep's day and were previously the same list.
                 */
                'meeting_platform' => $d->meeting_platform,
                'meeting_platform_label' => Demo::PLATFORMS[$d->meeting_platform] ?? null,
                'is_field_visit' => $d->meeting_platform === Demo::PLATFORM_IN_PERSON,
                'meeting_link' => $d->meeting_link,
                'meeting_id' => $d->meeting_id,
                'passcode' => $d->passcode,
                'location' => $d->location,
                'confirmation_status' => $d->confirmation_status?->value ?? 'unconfirmed',
                'notes' => $d->notes,
            ])->values()->toArray();

        // 4. My Active Trials
        $myTrials = Trial::query()
            ->where(function ($q) use ($user, $myLeadIds) {
                $q->where('assigned_user_id', $user->id)
                    ->orWhereIn('lead_id', $myLeadIds);
            })
            ->where('status', TrialStatus::Active)
            ->with(['lead:id,company_name,contact_name,phone,status'])
            ->orderBy('end_date')
            ->limit(self::WORKSPACE_LIST_LIMIT)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'lead_id' => $t->lead_id,
                'company_name' => $t->lead?->company_name ?? 'Trial Clinic',
                'contact_name' => $t->lead?->contact_name ?? '',
                'phone' => $t->lead?->phone ?? '',
                'plan' => $t->plan ?? 'Standard',
                'start_date' => $t->start_date ? $t->start_date->format('d M Y') : '',
                'end_date' => $t->end_date ? $t->end_date->format('d M Y') : '',
                'days_remaining' => $t->end_date ? max(0, (int) now()->diffInDays($t->end_date, false)) : 0,
                'is_ending_soon' => $t->end_date ? now()->diffInDays($t->end_date, false) <= 3 : false,
                /*
                 * A distinct, tighter window from `is_ending_soon` (3 days):
                 * the My Work cockpit's own "expiring" queue reads inside 48
                 * hours specifically. Kept separate rather than narrowing the
                 * 3-day flag, which the Trials board and Deal 360 already key
                 * off of.
                 */
                'hours_remaining' => $t->end_date ? max(0, (int) now()->diffInHours($t->end_date, false)) : null,
                'is_expiring_48h' => $t->end_date
                    ? now()->diffInHours($t->end_date, false) >= 0 && now()->diffInHours($t->end_date, false) <= 48
                    : false,
                'usage_percent' => (float) ($t->usage_percent ?? 0),
                'telemetry' => $t->usage_telemetry ?: null,
                'next_action' => $t->next_action ?? 'Review trial engagement',
            ])->values()->toArray();

        // 5. My Quotations (Canonical CompanyQuotation)
        $employee = CompanyEmployee::where('user_id', $user->id)->first();
        $employeeId = $employee?->id;

        // A quotation inherits its lead's organization_id, which is null for the
        // entire pre-Won working set — so filtering on the tenant alone hid
        // every quotation a rep had actually raised. Null is in scope.
        $myQuotations = CompanyQuotation::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where(
                fn ($inner) => $inner->where('organization_id', $orgId)->orWhereNull('organization_id')
            ))
            ->where(function ($q) use ($employeeId, $myLeadIds) {
                $hasCondition = false;
                if ($employeeId) {
                    $q->where('prepared_by_id', $employeeId)
                        ->orWhere('assigned_id', $employeeId);
                    $hasCondition = true;
                }
                if ($myLeadIds->isNotEmpty()) {
                    if ($hasCondition) {
                        $q->orWhereIn('lead_id', $myLeadIds);
                    } else {
                        $q->whereIn('lead_id', $myLeadIds);
                    }
                    $hasCondition = true;
                }
                if (! $hasCondition) {
                    $q->whereRaw('1 = 0');
                }
            })
            ->whereIn('status', [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Approved])
            ->with(['lead:id,company_name,contact_name,phone'])
            ->latest('updated_at')
            ->limit(self::WORKSPACE_LIST_LIMIT)
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'lead_id' => $q->lead_id,
                'company_name' => $q->lead?->company_name ?? 'Quotation Client',
                'contact_name' => $q->lead?->contact_name ?? '',
                'quotation_number' => $q->quotation_number ?? "QT-{$q->id}",
                'version' => $q->version ?? 1,
                'grand_total' => (float) ($q->grand_total ?? 0),
                'status' => $q->status?->value ?? 'sent',
                'valid_until' => $q->valid_until ? $q->valid_until->format('d M Y') : null,
                'is_expiring_soon' => $q->valid_until ? now()->diffInDays($q->valid_until, false) <= 3 : false,
            ])->values()->toArray();

        // 6. My Leads Needing Next Action (Active Assigned Leads with no scheduled follow-up)
        $needsNextAction = (clone $myActiveLeadsQuery)
            ->whereNull('next_followup_at')
            ->latest('updated_at')
            ->limit(self::WORKSPACE_LIST_LIMIT)
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'company_name' => $l->company_name,
                'contact_name' => $l->contact_name,
                'phone' => $l->phone,
                'stage' => $l->status->label(),
                'stage_key' => $l->status->value,
                'priority' => $l->priority?->value ?? 'medium',
                'last_contact' => $l->updated_at ? $l->updated_at->diffForHumans() : 'Never',
                'expected_mrr' => (float) ($l->expected_mrr ?? 0),
            ])->values()->toArray();

        // 7. My Win-Backs (Lost Leads assigned to user)
        $winbacks = (clone $myLeadsBaseQuery)
            ->where('status', LeadStatus::Lost)
            ->latest('updated_at')
            ->limit(self::WORKSPACE_LIST_LIMIT)
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'company_name' => $l->company_name,
                'contact_name' => $l->contact_name,
                'phone' => $l->phone,
                'loss_reason' => $l->lost_reason ?? 'Pricing / Competitor',
                'last_contact' => $l->updated_at ? $l->updated_at->diffForHumans() : '',
                /*
                 * The field the win-back automation actually reads and writes
                 * (`scheduleWinBack()`, `processDueWinBacks()`), not
                 * `next_followup_at` — a lead can carry both, unrelated to
                 * each other, and this tab exists to answer "when does this
                 * lead's win-back fire", not "when is its next follow-up".
                 */
                'scheduled_winback' => $l->winback_scheduled_at ? $l->winback_scheduled_at->format('d M Y') : 'Unscheduled',
            ])->values()->toArray();

        // 8. My All Assigned Leads (Clean filtered queue)
        $leadsQuery = (clone $myLeadsBaseQuery)
            ->with(['assignedUser:id,name,email'])
            ->latest('updated_at')
            ->limit(self::WORKSPACE_LIST_LIMIT);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $leadsQuery->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['stage']) && $filters['stage'] !== 'all') {
            $leadsQuery->where('status', $filters['stage']);
        }

        $allAssignedLeads = $leadsQuery->get()->map(fn ($l) => [
            'id' => $l->id,
            'company_name' => $l->company_name,
            'contact_name' => $l->contact_name,
            'email' => $l->email,
            'phone' => $l->phone,
            'stage' => $l->status->label(),
            'stage_key' => $l->status->value,
            'priority' => $l->priority?->value ?? 'medium',
            'expected_mrr' => (float) ($l->expected_mrr ?? 0),
            'health_score' => (int) ($l->health_score ?? 75),
            'next_followup_at' => $l->next_followup_at ? $l->next_followup_at->format('d M Y') : null,
            'last_contact' => $l->updated_at ? $l->updated_at->diffForHumans() : '',
        ])->values()->toArray();

        // 9. Return Pure Actionable Workstation Payload
        return [
            /*
             * What is sitting there with nobody on it.
             *
             * A personal work list is only useful while work has been assigned
             * to you. Before a team exists — or on any day the routing rules
             * matched nothing — this page had nothing to say and said it seven
             * times over: "Follow-ups 0 · Demos 0 · Trials 0 · Quotations 0 ·
             * Needs an action 0 · Win-backs 0 · My leads 0".
             *
             * Unclaimed leads *are* the work in that situation, so the page can
             * answer honestly instead of looking broken.
             */
            'unclaimed' => $this->unclaimedLeads($user),
            'workload' => [
                'counts' => [
                    'overdue_followups' => count($followups['overdue']),
                    'due_today_followups' => count($followups['due_today']),
                    'upcoming_followups' => count($followups['upcoming']),
                    'demos' => count($myDemos),
                    'active_trials' => count($myTrials),
                    'quotations' => count($myQuotations),
                    'needs_next_action' => count($needsNextAction),
                    'winbacks' => count($winbacks),
                    'total_assigned_leads' => count($allAssignedLeads),
                ],
                'followups' => $followups,
                'demos' => $myDemos,
                'trials' => $myTrials,
                'quotations' => $myQuotations,
                'needs_next_action' => $needsNextAction,
                'winbacks' => $winbacks,
                'assigned_leads' => $allAssignedLeads,
            ],
            'filters' => $filters,
        ];
    }

    /**
     * Build the lean, high-performance Pipeline Kanban payload with 7-column grouping,
     * real operational metrics, server-side filtering, and explicit frontend DTO contracts.
     */
    public function getPipelinePayload(array $filters = [], ?User $user = null): array
    {
        $orgId = $user ? $user->resolveOrganizationId() : null;
        $isMasterAdmin = $user ? $user->isMasterAdmin() : true;

        // 1. Base query from the single authoritative scope — same rows the
        //    directory and the Overview see, so the board cannot disagree.
        $baseQuery = CrmScope::leads($user);

        // Scope to employee assigned leads if employee persona without team/admin view
        if (! empty($filters['scoped_to_user']) && $user) {
            $baseQuery->where('assigned_user_id', $user->id);
        }

        // 2. Real Database Operational KPI Metrics
        $activeQuery = (clone $baseQuery)->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost]);
        $totalActiveDeals = (clone $activeQuery)->count();
        $hotDeals = (clone $activeQuery)->where('priority', LeadPriority::High)->count();
        $overdueDeals = (clone $activeQuery)
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<', now())
            ->count();
        $stalledDeals = (clone $activeQuery)
            ->where(function ($q) {
                $q->where('updated_at', '<', now()->subDays(14))
                    ->orWhere(function ($sq) {
                        $sq->whereNotNull('next_followup_at')
                            ->where('next_followup_at', '<', now()->subDays(3));
                    })
                    ->orWhereNull('next_followup_at');
            })
            ->count();
        $unassignedDeals = (clone $activeQuery)
            ->whereNull('assigned_user_id')
            ->count();

        $metrics = [
            'total_active_deals' => $totalActiveDeals,
            'hot_deals' => $hotDeals,
            'overdue_deals' => $overdueDeals,
            'stalled_deals' => $stalledDeals,
            'unassigned_deals' => $unassignedDeals,
        ];

        // 3. Build Filtered Query
        $filteredQuery = clone $baseQuery;

        // Search Filter
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $filteredQuery->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('assignedUser', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Assigned Rep Filter
        if (! empty($filters['assigned_user_id']) && $filters['assigned_user_id'] !== 'all') {
            if ($filters['assigned_user_id'] === 'unassigned') {
                $filteredQuery->whereNull('assigned_user_id');
            } else {
                $filteredQuery->where('assigned_user_id', (int) $filters['assigned_user_id']);
            }
        }

        // Priority Filter
        if (! empty($filters['priority']) && $filters['priority'] !== 'all') {
            $filteredQuery->where('priority', $filters['priority']);
        }

        // Source Filter
        if (! empty($filters['source']) && $filters['source'] !== 'all') {
            $filteredQuery->where('source', $filters['source']);
        }

        // City Filter
        if (! empty($filters['city']) && $filters['city'] !== 'all') {
            $city = $filters['city'];
            $filteredQuery->where(function ($q) use ($city) {
                $q->whereHas('organization', fn ($oq) => $oq->where('city', $city))
                    ->orWhereHas('marketingContact', fn ($mq) => $mq->where('city', $city));
            });
        }

        // State Filter
        if (! empty($filters['state']) && $filters['state'] !== 'all') {
            $state = $filters['state'];
            $filteredQuery->whereHas('organization', fn ($oq) => $oq->where('state', $state));
        }

        // Overdue Only Filter
        if (! empty($filters['overdue_only']) && filter_var($filters['overdue_only'], FILTER_VALIDATE_BOOLEAN)) {
            $filteredQuery->whereNotNull('next_followup_at')
                ->where('next_followup_at', '<', now());
        }

        // Stalled Only Filter
        if (! empty($filters['stalled_only']) && filter_var($filters['stalled_only'], FILTER_VALIDATE_BOOLEAN)) {
            $filteredQuery->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost])
                ->where(function ($q) {
                    $q->where('updated_at', '<', now()->subDays(14))
                        ->orWhere(function ($sq) {
                            $sq->whereNotNull('next_followup_at')
                                ->where('next_followup_at', '<', now()->subDays(3));
                        })
                        ->orWhereNull('next_followup_at');
                });
        }

        // Stage Filter
        if (! empty($filters['stage']) && $filters['stage'] !== 'all') {
            $stage = $filters['stage'];
            if ($stage === 'new_assigned') {
                $filteredQuery->whereIn('status', [LeadStatus::New, LeadStatus::Assigned]);
            } elseif ($stage === 'contacted') {
                $filteredQuery->where('status', LeadStatus::Contacted);
            } elseif ($stage === 'demo') {
                $filteredQuery->whereIn('status', [LeadStatus::DemoScheduled, LeadStatus::DemoCompleted]);
            } elseif ($stage === 'trial') {
                $filteredQuery->where('status', LeadStatus::TrialActive);
            } elseif ($stage === 'commercial') {
                $filteredQuery->whereIn('status', [LeadStatus::Negotiation, LeadStatus::QuotationSent, LeadStatus::QuotationApproved]);
            } elseif ($stage === 'won') {
                $filteredQuery->whereIn('status', [LeadStatus::PaymentReceived, LeadStatus::Won]);
            } elseif ($stage === 'lost') {
                $filteredQuery->where('status', LeadStatus::Lost);
            } else {
                $filteredQuery->where('status', $stage);
            }
        }

        // 4. Fetch Lean Leads
        //
        // Bounded: the kanban renders every card client-side, so an unbounded
        // fetch turns a large pipeline into a multi-megabyte Inertia payload.
        // The column counts below are computed from the full query, so the
        // totals stay honest even when the cards are truncated.
        $totalMatching = (clone $filteredQuery)->count();

        $leads = $filteredQuery
            ->with([
                'assignedUser:id,name,email',
                'organization:id,name,city,state',
                'marketingContact:id,city',
            ])
            ->latest('updated_at')
            ->limit(self::PIPELINE_CARD_LIMIT)
            ->get();

        // 5. Initialize 7 Canonical Visual Stage Columns
        $stageMapping = [
            'new' => 'new_assigned',
            'assigned' => 'new_assigned',
            'contacted' => 'contacted',
            'demo_scheduled' => 'demo',
            'demo_completed' => 'demo',
            'trial_active' => 'trial',
            'negotiation' => 'commercial',
            'quotation_sent' => 'commercial',
            'quotation_approved' => 'commercial',
            'payment_received' => 'won',
            'won' => 'won',
            'lost' => 'lost',
        ];

        $columns = [
            'new_assigned' => [
                'id' => 'new_assigned',
                'title' => 'New & Assigned',
                'description' => 'Newly ingested and assigned prospect leads',
                'color' => 'slate',
                'default_status' => 'new',
                'cards' => [],
                'total_count' => 0,
                'total_mrr' => 0,
            ],
            'contacted' => [
                'id' => 'contacted',
                'title' => 'Contacted',
                'description' => 'Initial outreach and sales qualification in progress',
                'color' => 'blue',
                'default_status' => 'contacted',
                'cards' => [],
                'total_count' => 0,
                'total_mrr' => 0,
            ],
            'demo' => [
                'id' => 'demo',
                'title' => 'Demo',
                'description' => 'Product demonstration scheduled or completed',
                'color' => 'indigo',
                'default_status' => 'demo_scheduled',
                'cards' => [],
                'total_count' => 0,
                'total_mrr' => 0,
            ],
            'trial' => [
                'id' => 'trial',
                'title' => 'Trial',
                'description' => 'Active clinical evaluation in progress',
                'color' => 'teal',
                'default_status' => 'trial_active',
                'cards' => [],
                'total_count' => 0,
                'total_mrr' => 0,
            ],
            'commercial' => [
                'id' => 'commercial',
                'title' => 'Commercial',
                'description' => 'Negotiation, proposals, and quotation approval',
                'color' => 'amber',
                'default_status' => 'quotation_sent',
                'cards' => [],
                'total_count' => 0,
                'total_mrr' => 0,
            ],
            'won' => [
                'id' => 'won',
                'title' => 'Closed Won',
                'description' => 'Payment received & customer provisioned',
                'color' => 'emerald',
                'default_status' => 'won',
                'cards' => [],
                'total_count' => 0,
                'total_mrr' => 0,
            ],
            'lost' => [
                'id' => 'lost',
                'title' => 'Closed Lost',
                'description' => 'Lost opportunities with structured loss triage',
                'color' => 'rose',
                'default_status' => 'lost',
                'cards' => [],
                'total_count' => 0,
                'total_mrr' => 0,
            ],
        ];

        // 6. Map Cards into Columns
        foreach ($leads as $lead) {
            $rawStatus = $lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status;
            $stageKey = $stageMapping[$rawStatus] ?? 'new_assigned';
            $statusLabel = $lead->status instanceof LeadStatus ? $lead->status->label() : ucfirst(str_replace('_', ' ', $rawStatus));
            $priorityVal = $lead->priority instanceof \BackedEnum ? $lead->priority->value : (string) ($lead->priority ?? 'warm');

            $mrr = (float) ($lead->expected_mrr ?? 0);
            $arr = (float) ($lead->expected_arr ?: ($lead->expected_mrr ? $lead->expected_mrr * 12 : 0));

            $isOverdue = $lead->next_followup_at ? $lead->next_followup_at->isPast() : false;
            $isStalled = ! in_array($rawStatus, ['won', 'lost']) && (
                ($lead->updated_at && $lead->updated_at->lt(now()->subDays(14))) ||
                ($lead->next_followup_at && $lead->next_followup_at->lt(now()->subDays(3))) ||
                is_null($lead->next_followup_at)
            );

            $card = [
                'id' => $lead->id,
                'company_name' => $lead->company_name,
                'slug' => $lead->slug,
                'contact_name' => $lead->contact_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'address' => $lead->address,
                'city' => $lead->city ?? $lead->organization?->city ?? $lead->marketingContact?->city ?? null,
                'state' => $lead->state ?? $lead->organization?->state ?? null,
                'has_complete_address' => $lead->has_complete_address,
                'status' => $rawStatus,
                'status_label' => $statusLabel,
                'stage_group' => $stageKey,
                'priority' => $priorityVal,
                'source' => $lead->source ?? 'manual_entry',
                'source_label' => Lead::sources()[$lead->source] ?? ucfirst(str_replace('_', ' ', $lead->source ?? 'manual_entry')),
                'organization_type' => $lead->organization_type instanceof \BackedEnum ? $lead->organization_type->value : ((string) $lead->organization_type ?: null),
                'branch_count' => (int) ($lead->branch_count ?? 1),
                'expected_mrr' => $mrr,
                'expected_arr' => $arr,
                'lead_score' => (int) ($lead->lead_score ?? $lead->automated_score ?? 0),
                'next_best_action' => $lead->next_best_action,
                'next_followup_at' => $lead->next_followup_at ? $lead->next_followup_at->toISOString() : null,
                'next_followup_formatted' => $lead->next_followup_at ? $lead->next_followup_at->format('d M, h:i A') : null,
                'is_followup_overdue' => $isOverdue,
                'is_stalled' => $isStalled,
                'followup_notes' => $lead->followup_notes,
                'assigned_user' => $lead->assignedUser ? [
                    'id' => $lead->assignedUser->id,
                    'name' => $lead->assignedUser->name,
                    'email' => $lead->assignedUser->email,
                    'initials' => collect(explode(' ', $lead->assignedUser->name))->map(fn ($n) => $n[0] ?? '')->take(2)->join(''),
                ] : null,
                'created_at' => $lead->created_at ? $lead->created_at->format('d M Y') : null,
                'updated_at' => $lead->updated_at ? $lead->updated_at->diffForHumans() : null,
                'lost_reason' => $lead->lost_reason instanceof \BackedEnum ? $lead->lost_reason->value : (string) $lead->lost_reason,
            ];

            $columns[$stageKey]['cards'][] = $card;
            $columns[$stageKey]['total_count']++;
            if ($mrr > 0) {
                $columns[$stageKey]['total_mrr'] += $mrr;
            }
        }

        // 7. Assignable CRM Users & Location Lists
        $assignableUsers = User::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->where('user_type', '!=', UserType::ClinicUser)
            ->select('id', 'name', 'email')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'initials' => collect(explode(' ', $u->name))->map(fn ($n) => $n[0] ?? '')->take(2)->join(''),
            ])
            ->values()
            ->toArray();

        $availableCities = Organization::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('id', $orgId))
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->pluck('city')
            ->merge(
                MarketingContact::query()
                    ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->distinct()
                    ->pluck('city')
            )
            ->merge(
                Lead::query()
                    ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->distinct()
                    ->pluck('city')
            )
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        $availableStates = Organization::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('id', $orgId))
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->distinct()
            ->pluck('state')
            ->merge(
                Lead::query()
                    ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
                    ->whereNotNull('state')
                    ->where('state', '!=', '')
                    ->distinct()
                    ->pluck('state')
            )
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        return [
            'columns' => array_values($columns),
            'metrics' => $metrics,
            'filters' => $filters,
            'assignableUsers' => $assignableUsers,
            'availableCities' => $availableCities,
            'availableStates' => $availableStates,
            'leadSources' => Lead::sources(),
            'organizationTypes' => $this->organizationTypeOptions(),
            'priorities' => ['high' => 'High / Hot', 'medium' => 'Medium / Warm', 'low' => 'Low / Cold'],
            'pagination' => [
                'total_matching' => $totalMatching,
                'rendered' => $leads->count(),
                'card_limit' => self::PIPELINE_CARD_LIMIT,
                'is_truncated' => $totalMatching > $leads->count(),
            ],
        ];
    }

    /**
     * Build and return the complete Sales Operating System payload.
     */
    public function getSalesOsPayload(array $filters = [], ?int $activeLeadId = null, ?User $user = null): array
    {
        $query = Lead::with([
            'assignedUser:id,name,email',
            'organization:id,name,email',
            'callLogs' => fn ($q) => $q->latest()->take(10),
            'emailThreads' => fn ($q) => $q->latest()->take(10),
            'demos' => fn ($q) => $q->latest('scheduled_at')->take(2),
            'trials' => fn ($q) => $q->latest()->take(1),
            'quotations' => fn ($q) => $q->latest()->take(3),
            'activities' => fn ($q) => $q->latest()->take(15),
            'tasks' => fn ($q) => $q->where('status', '!=', 'cancelled')->latest('due_at')->take(5),
        ]);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['organization_type'])) {
            $query->where('organization_type', $filters['organization_type']);
        }

        if (! empty($filters['assigned_user_id'])) {
            $query->where('assigned_user_id', $filters['assigned_user_id']);
        }

        // Scope to employee assigned leads if explicitly requested or employee persona
        if (! empty($filters['scoped_to_user']) && $user) {
            $query->where('assigned_user_id', $user->id);
        }

        $allLeads = $query->latest('updated_at')->get();

        $activeLead = null;
        if ($activeLeadId) {
            $activeLead = $allLeads->firstWhere('id', $activeLeadId);
        }

        if (! $activeLead && $allLeads->isNotEmpty()) {
            $activeLead = $allLeads->first();
        }

        $copilotAnalysis = $activeLead ? $this->copilotService->analyzeLead($activeLead) : null;

        return [
            'metrics' => $this->getPipelineMetrics($allLeads),
            'pipelineStages' => $this->groupLeadsByStage($allLeads),
            'leadsList' => $allLeads,
            'activeLead' => $activeLead,
            'copilotAnalysis' => $copilotAnalysis,
            'filters' => $filters,
            'assignableUsers' => User::query()->select('id', 'name', 'email')->get(),
            'todaysBriefing' => $user ? $this->getEmployeeBriefingPayload($user) : null,
        ];
    }

    /**
     * Build Today's Focus briefing payload for employee operational workspace.
     */
    public function getEmployeeBriefingPayload(User $user): array
    {
        $today = Carbon::today();
        $myLeadIds = Lead::where('assigned_user_id', $user->id)->pluck('id');

        $myActiveLeads = Lead::where('assigned_user_id', $user->id)
            ->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost]);

        $callsTodayCount = LeadCallLog::whereIn('lead_id', $myLeadIds)
            ->whereDate('created_at', $today)
            ->count();

        $followupsToday = (clone $myActiveLeads)
            ->whereNotNull('next_followup_at')
            ->whereDate('next_followup_at', $today)
            ->get();

        $overdueFollowups = (clone $myActiveLeads)
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<', $today->startOfDay())
            ->get();

        $todaysDemos = Demo::whereIn('lead_id', $myLeadIds)
            ->whereDate('scheduled_at', $today)
            ->with('lead')
            ->get();

        $upcomingDemos = Demo::whereIn('lead_id', $myLeadIds)
            ->whereBetween('scheduled_at', [$today->copy()->addDay(), $today->copy()->addDays(7)])
            ->with('lead')
            ->get();

        $leadsWithoutNextAction = (clone $myActiveLeads)
            ->whereNull('next_followup_at')
            ->get();

        $leadsWaitingResponse = (clone $myActiveLeads)
            ->whereIn('status', [LeadStatus::Contacted, LeadStatus::QuotationSent, LeadStatus::Negotiation])
            ->get();

        $trialFollowups = Trial::whereIn('lead_id', $myLeadIds)
            ->where('status', 'active')
            ->with('lead')
            ->get();

        $highPriorityLeads = (clone $myActiveLeads)
            ->where('priority', LeadPriority::High)
            ->get();

        $newLeadsCount = Lead::where('assigned_user_id', $user->id)
            ->where('status', LeadStatus::New)
            ->count();

        $quotesPending = CompanyQuotation::whereIn('lead_id', $myLeadIds)
            ->where('status', QuotationStatus::Sent)
            ->with('lead')
            ->get();

        return [
            'calls_today_count' => $callsTodayCount,
            'followups_today_count' => $followupsToday->count(),
            'followups_today' => $followupsToday,
            'overdue_followups_count' => $overdueFollowups->count(),
            'overdue_followups' => $overdueFollowups,
            'todays_demos_count' => $todaysDemos->count(),
            'todays_demos' => $todaysDemos,
            'upcoming_demos_count' => $upcomingDemos->count(),
            'upcoming_demos' => $upcomingDemos,
            'leads_without_next_action_count' => $leadsWithoutNextAction->count(),
            'leads_without_next_action' => $leadsWithoutNextAction,
            'leads_waiting_response_count' => $leadsWaitingResponse->count(),
            'leads_waiting_response' => $leadsWaitingResponse,
            'trial_followups_count' => $trialFollowups->count(),
            'trial_followups' => $trialFollowups,
            'high_priority_leads_count' => $highPriorityLeads->count(),
            'high_priority_leads' => $highPriorityLeads,
            'new_leads_count' => $newLeadsCount,
            'quotations_pending_count' => $quotesPending->count(),
            'quotations_pending' => $quotesPending,
        ];
    }

    /**
     * Build CRM Admin / Manager Overview Follow-up Health Payload.
     */
    public function getOverviewPayload(?User $user = null): array
    {
        $today = Carbon::today();
        $orgId = $user?->resolveOrganizationId();
        $isMasterAdmin = $user?->isMasterAdmin() ?? false;

        $leadsQuery = Lead::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId));

        $activeLeadsQuery = (clone $leadsQuery)->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost]);

        $totalActiveLeads = (clone $activeLeadsQuery)->count();

        $followupsTodayCount = (clone $activeLeadsQuery)
            ->whereNotNull('next_followup_at')
            ->whereDate('next_followup_at', $today)
            ->count();

        $overdueFollowupsCount = (clone $activeLeadsQuery)
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<', $today->startOfDay())
            ->count();

        $leadsWithoutNextActionCount = (clone $activeLeadsQuery)
            ->whereNull('next_followup_at')
            ->count();

        $activeLeadIds = (clone $leadsQuery)->pluck('id');

        $callsTodayCount = LeadCallLog::whereIn('lead_id', $activeLeadIds)
            ->whereDate('created_at', $today)
            ->count();

        $followupsCompletedTodayCount = Activity::whereIn('subject_id', $activeLeadIds)
            // Both spellings: the morph alias, and the fully qualified name that
            // `Auditable` and one seeder wrote. Matching only the alias
            // undercounts, which is what this line used to do.
            ->whereIn('subject_type', ['lead', Lead::class])
            ->where('type', ActivityType::FollowUp)
            ->whereDate('created_at', $today)
            ->count();

        $contactedLeadsCount = (clone $activeLeadsQuery)
            ->whereNotIn('status', [LeadStatus::New, LeadStatus::Assigned])
            ->count();

        $uncontactedLeadsCount = (clone $activeLeadsQuery)
            ->whereIn('status', [LeadStatus::New, LeadStatus::Assigned])
            ->count();

        // Employee Workload Summary
        $reps = User::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->where('user_type', '!=', UserType::ClinicUser)
            ->select('id', 'name', 'email')
            ->get()
            ->map(function ($rep) use ($today) {
                $assignedLeads = Lead::where('assigned_user_id', $rep->id)
                    ->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost]);

                $overdueCount = (clone $assignedLeads)
                    ->whereNotNull('next_followup_at')
                    ->where('next_followup_at', '<', $today->startOfDay())
                    ->count();

                $assignedLeadIds = (clone $assignedLeads)->pluck('id');

                $callsCount = LeadCallLog::whereIn('lead_id', $assignedLeadIds)
                    ->whereDate('created_at', $today)
                    ->count();

                $completedTasksCount = Task::where('assigned_user_id', $rep->id)
                    ->where('status', TaskStatus::Completed)
                    ->whereDate('completed_at', $today)
                    ->count();

                return [
                    'id' => $rep->id,
                    'name' => $rep->name,
                    'email' => $rep->email,
                    'assigned_leads_count' => (clone $assignedLeads)->count(),
                    'overdue_followups_count' => $overdueCount,
                    'calls_today_count' => $callsCount,
                    'completed_tasks_today_count' => $completedTasksCount,
                ];
            });

        return [
            'total_active_leads' => $totalActiveLeads,
            'followups_today_count' => $followupsTodayCount,
            'overdue_followups_count' => $overdueFollowupsCount,
            'leads_without_next_action_count' => $leadsWithoutNextActionCount,
            'calls_today_count' => $callsTodayCount,
            'followups_completed_today_count' => $followupsCompletedTodayCount,
            'contacted_leads_count' => $contactedLeadsCount,
            'uncontacted_leads_count' => $uncontactedLeadsCount,
            'employee_workload' => $reps,
        ];
    }

    /**
     * Group leads into unified stages for Kanban pipeline view.
     */
    protected function groupLeadsByStage($leads): array
    {
        $stages = [
            'new' => ['key' => 'new', 'label' => 'New Leads', 'color' => 'slate', 'leads' => []],
            'contacted' => ['key' => 'contacted', 'label' => 'Qualified / Contacted', 'color' => 'blue', 'leads' => []],
            'demo_scheduled' => ['key' => 'demo_scheduled', 'label' => 'Demo Scheduled', 'color' => 'indigo', 'leads' => []],
            'demo_completed' => ['key' => 'demo_completed', 'label' => 'Demo Completed', 'color' => 'violet', 'leads' => []],
            'trial_active' => ['key' => 'trial_active', 'label' => 'Trial Active', 'color' => 'teal', 'leads' => []],
            'quotation_sent' => ['key' => 'quotation_sent', 'label' => 'Quotation Issued', 'color' => 'amber', 'leads' => []],
            'negotiation' => ['key' => 'negotiation', 'label' => 'Negotiation', 'color' => 'orange', 'leads' => []],
            'won' => ['key' => 'won', 'label' => 'Converted (Won)', 'color' => 'emerald', 'leads' => []],
            'lost' => ['key' => 'lost', 'label' => 'Lost / Archival', 'color' => 'rose', 'leads' => []],
        ];

        foreach ($leads as $lead) {
            $statusKey = is_object($lead->status) && isset($lead->status->value)
                ? $lead->status->value
                : (string) $lead->status;

            if (isset($stages[$statusKey])) {
                $stages[$statusKey]['leads'][] = $lead;
            } elseif (in_array($statusKey, ['qualified', 'assigned'])) {
                $stages['contacted']['leads'][] = $lead;
            } elseif (in_array($statusKey, ['trial_started', 'trial_completed'])) {
                $stages['trial_active']['leads'][] = $lead;
            } elseif (in_array($statusKey, ['quotation_approved', 'agreement_accepted'])) {
                $stages['quotation_sent']['leads'][] = $lead;
            } else {
                $stages['new']['leads'][] = $lead;
            }
        }

        return array_values($stages);
    }

    /**
     * Calculate KPI metrics for Lead Workspace header.
     */
    protected function getPipelineMetrics($leads): array
    {
        $totalPipelineValue = $leads->sum('expected_mrr');
        $totalLeadsCount = $leads->count();
        $convertedCount = $leads->filter(fn ($l) => (string) ($l->status->value ?? $l->status) === 'won')->count();
        $winRate = $totalLeadsCount > 0 ? round(($convertedCount / $totalLeadsCount) * 100, 1) : 0;
        $urgentLeadsCount = $leads->filter(fn ($l) => $l->priority === 'high' || $l->priority === 'critical')->count();

        return [
            'totalPipelineValue' => '₹ '.number_format($totalPipelineValue),
            'totalLeadsCount' => $totalLeadsCount,
            'winRate' => "{$winRate}%",
            'urgentLeadsCount' => $urgentLeadsCount,
            'avgDealVelocity' => '14 Days',
        ];
    }

    /**
     * Transition a lead to a new stage and record audit log event.
     */
    /**
     * Transition a lead to a new stage with strict precursor guard validation and audit log event.
     */
    public function transitionStage(Lead $lead, string $newStage, ?User $user = null, ?string $reason = null, bool $force = false): Lead
    {
        $previousStage = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
        $targetStatus = LeadStatus::from($newStage);

        /*
         * Is this edge legal at all?
         *
         * Checked here rather than at the request boundary because it is an
         * invariant, not a validation rule: a won deal is a live customer, and
         * it must be as impossible to regress one from a job, a seeder or an
         * API client as it is from the stepper. The prerequisites stay at the
         * boundary — see `DealStageService` for why the two are separated.
         *
         * `$force` exists for exactly one caller: `reopen()`, which is the
         * deliberate, justified way out of Lost.
         */
        if (! $force) {
            $blocked = app(DealStageService::class)->blockReason($lead, $newStage);

            if ($blocked !== null) {
                throw new IllegalStageTransition($previousStage, $newStage, $blocked);
            }
        }

        // Strict Transition Guards
        if ($targetStatus === LeadStatus::QuotationSent) {
            $hasQuotation = CompanyQuotation::where('lead_id', $lead->id)->exists();
            if (! $hasQuotation) {
                throw new InvalidArgumentException("Cannot transition lead '{$lead->company_name}' to Quotation Sent without an existing quotation.");
            }
        }

        if ($targetStatus === LeadStatus::DemoCompleted) {
            $hasDemo = Demo::where('lead_id', $lead->id)->exists();
            if (! $hasDemo) {
                throw new InvalidArgumentException("Cannot transition lead '{$lead->company_name}' to Demo Completed without a scheduled demo.");
            }
        }

        if ($targetStatus === LeadStatus::TrialActive) {
            $hasTrial = Trial::where('lead_id', $lead->id)->exists();
            if (! $hasTrial) {
                throw new InvalidArgumentException("Cannot transition lead '{$lead->company_name}' to Trial Active without an active trial record.");
            }
        }

        $lead->status = $targetStatus;

        if ($targetStatus === LeadStatus::Lost && $reason) {
            $lead->lost_reason = $reason;
        }

        // Qualification is the moment a lead leaves intake. Stamped once and
        // never overwritten, so re-qualifying a reopened deal does not lose
        // when it was first judged real.
        if (! in_array($newStage, DealStageService::UNQUALIFIED, true)
            && $targetStatus !== LeadStatus::Lost
            && $lead->qualified_at === null) {
            $lead->qualified_at = now();
        }

        /*
         * Everything up to (but not including) provisioning commits as one
         * unit. This used to cancel a Won deal's pending tasks with a raw
         * mass update, then save the lead's own status change as a separate,
         * unguarded statement — a failure in between (the save itself, or a
         * `LeadObserver` hook it fires) left those tasks cancelled against a
         * lead that was never actually marked Won. Wrapping the two together
         * means either both land or neither does.
         */
        DB::transaction(function () use ($lead, $targetStatus, $user, $reason) {
            /*
             * Lifecycle policy — Won/Lost task & follow-up parity:
             *
             * Won and Lost are the two ways a deal stops being actively sold.
             * Neither should leave stale sales work behind: an open task or a
             * pending follow-up date is a promise to keep selling, and there is
             * no more selling to do once the deal is decided, whichever way it
             * went. So both states cancel every pending task on the lead and
             * clear the cached follow-up date the same way.
             *
             * They diverge immediately after this block: only Won provisions a
             * tenant and suppresses marketing (below, and in `LeadObserver`).
             * Lost's own follow-up work — recording a loss reason, scheduling a
             * win-back — stays a deliberate, opt-in step (`triageLostLead()`,
             * `scheduleWinBack()`), never an automatic consequence of landing on
             * Lost, so a rep still chooses if and when to re-engage.
             */
            if ($targetStatus === LeadStatus::Won || $targetStatus === LeadStatus::Lost) {
                $lead->next_followup_at = null;
                $lead->followup_notes = null;

                // A mass update bypasses model events, so the follow-up cache is
                // reconciled explicitly after the save below.
                Task::where('taskable_id', $lead->id)
                    ->where('taskable_type', $lead->getMorphClass())
                    ->where('status', TaskStatus::Pending->value)
                    ->update(['status' => TaskStatus::Cancelled->value]);
            }

            // The stage-change activity and the Won marketing hand-off are both
            // written by LeadObserver, which is the only hook a status change
            // cannot bypass. Handing it the reason and actor here keeps the single
            // record as rich as the one this method used to write itself.
            LeadObserver::$pendingStageContext = ['actor' => $user, 'reason' => $reason];

            try {
                $lead->save();
            } finally {
                LeadObserver::$pendingStageContext = null;
            }

            if ($targetStatus === LeadStatus::Won || $targetStatus === LeadStatus::Lost) {
                app(LeadFollowupSyncService::class)->sync($lead);
            }
        });

        /*
         * Provisioning stays outside that transaction, and outside any
         * transaction at all: it is a long-running orchestration, not an
         * audit side effect, and it must run after the Won status has
         * actually committed — never rolled back alongside a provisioning
         * failure, since the sale itself is already real by this point.
         *
         * `provision()` is idempotent on `customer_organization_id`, so a
         * failure here leaves a real, recoverable state — "Won, not yet
         * provisioned" — rather than an inconsistent one: nothing above is
         * undone, and calling `transitionStage()` to Won again (or any other
         * caller of `provision()`) picks up cleanly where this attempt left
         * off. The exception still surfaces to the caller after being logged
         * with enough context to find the deal, rather than disappearing
         * into a generic 500 with no trace of which lead needs attention.
         *
         * Guarded on the customer link, not the tenant one. `organization_id`
         * is stamped at creation with the acting user's org, so guarding on it
         * meant provisioning never ran for a lead created through the UI.
         */
        if ($targetStatus === LeadStatus::Won) {
            try {
                app(CustomerProvisioningEngine::class)->provision($lead);
            } catch (Throwable $e) {
                Log::error('Customer provisioning failed after a lead was marked Won.', [
                    'lead_id' => $lead->id,
                    'company_name' => $lead->company_name,
                    'exception' => $e,
                ]);

                throw $e;
            }
        }

        return $lead->fresh(['assignedUser', 'organization', 'activities']);
    }

    /**
     * Leads in this organization that nobody owns.
     *
     * Deliberately not filtered to a stage: an unowned lead at any point in the
     * pipeline is somebody's job that has not been picked up.
     *
     * @return array{count: int, items: array<int, array<string, mixed>>}
     */
    protected function unclaimedLeads(User $user): array
    {
        $orgId = $user->resolveOrganizationId();

        $query = Lead::query()
            ->whereNull('assigned_user_id')
            ->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost])
            ->when($orgId && ! $user->isMasterAdmin(), fn ($q) => $q->where('organization_id', $orgId));

        return [
            'count' => (clone $query)->count(),
            'items' => $query
                ->latest('created_at')
                ->limit(self::WORKSPACE_LIST_LIMIT)
                ->get()
                ->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'company_name' => $lead->company_name,
                    'contact_name' => $lead->contact_name,
                    'stage' => $lead->status instanceof LeadStatus ? $lead->status->label() : (string) $lead->status,
                    'stage_key' => $lead->status instanceof LeadStatus ? $lead->status->value : (string) $lead->status,
                    'city' => $lead->city,
                    'expected_mrr' => (float) ($lead->expected_mrr ?? 0),
                    // Whole days. `diffInDays` returns a float, so this was
                    // rendering "waiting 0.00892956788d" on screen.
                    'waiting_days' => (int) floor($lead->created_at?->diffInDays(now()) ?? 0),
                ])
                ->all(),
        ];
    }

    /**
     * Assign a single lead to a specific user — the shared mechanics used by
     * manual single-lead assignment, batch reassignment, and auto-assignment.
     * Handles the status transition, activity log, and (optionally) a
     * notification to the new owner. Task creation happens separately via
     * LeadObserver, which fires uniformly for every assignment path that
     * changes `assigned_user_id`, including this one.
     */
    public function assignLead(Lead $lead, User $user, ?User $actor = null, string $reason = 'manual', bool $notify = true, bool $createTask = true): Lead
    {
        if ($lead->assigned_user_id === $user->id) {
            return $lead;
        }

        /*
         * `createTask` exists for automatic assignment at intake.
         *
         * Handing a lead to somebody by hand is a decision, and the follow-up
         * task that comes with it is the point. Assignment at creation is not a
         * decision — it happens to every lead — and the task it generated set a
         * `next_followup_at` on every new record, which overrode dates the
         * creator had explicitly chosen and made every lead look freshly
         * actioned. The lead-created activity already says the lead exists;
         * what happens next is governed by the stage guards, not by a synthetic
         * task nobody asked for.
         */
        LeadObserver::$pendingAssignmentContext = [
            'actor' => $actor,
            'reason' => $reason,
            'task' => $createTask,
        ];

        $lead->assigned_user_id = $user->id;
        $lead->assigned_team_id = null;

        /*
         * Assigning no longer advances the stage.
         *
         * This used to push a New lead to Assigned, which is why that stage
         * existed at all. With every lead owned from the moment it is created,
         * promoting on assignment would move the whole book out of intake
         * before anybody had spoken to a single one of them. A lead stays New
         * until somebody contacts it.
         */
        $lead->save();

        LeadObserver::$pendingAssignmentContext = null;

        if ($notify) {
            $this->notifyUser(
                $user->id,
                'New Lead Assigned',
                "{$lead->company_name} has been assigned to you".($actor ? " by {$actor->name}." : ' automatically.'),
                NotificationPriority::MEDIUM,
                ['type' => 'lead_assigned', 'lead_id' => $lead->id]
            );
        }

        return $lead;
    }

    /**
     * Auto-assign a newly created, still-unowned lead using its organization's
     * active sales teams. Territory-strategy teams are tried first when the
     * lead has a state on file; otherwise falls back to the first
     * round-robin team. When neither claims the lead it goes to the fallback
     * owner rather than nowhere — see `assignToFallbackOwner`. Returns null
     * only when there is no fallback owner to find at all, which means the
     * system has no master admin.
     */
    public function autoAssignLead(Lead $lead): ?User
    {
        if ($lead->assigned_user_id || $lead->assigned_team_id || ! $lead->organization_id) {
            return null;
        }

        $teams = Team::query()
            ->where('organization_id', $lead->organization_id)
            ->where('is_active', true)
            ->get();

        if ($teams->isEmpty()) {
            return $this->assignToFallbackOwner($lead, 'no_teams_configured');
        }

        if ($lead->state) {
            foreach ($teams->where('assignment_strategy', Team::STRATEGY_TERRITORY) as $territoryTeam) {
                $user = $territoryTeam->userForState($lead->state);
                if ($user) {
                    $this->assignLead($lead, $user, null, 'territory_match');

                    return $user;
                }
            }
        }

        $roundRobinTeam = $teams->firstWhere('assignment_strategy', Team::STRATEGY_ROUND_ROBIN);
        if ($roundRobinTeam) {
            $user = $roundRobinTeam->nextRoundRobinUser();
            if ($user) {
                $this->assignLead($lead, $user, null, 'round_robin');

                return $user;
            }
        }

        return $this->assignToFallbackOwner($lead, 'no_rule_matched');
    }

    /**
     * Nobody matched, so the lead goes to a named owner rather than nowhere.
     *
     * Every path out of `autoAssignLead` used to be able to return null and
     * leave the lead sitting unowned — which is not a rare edge: with no teams
     * configured it was *every* lead, and six of the first fourteen deals had
     * no owner as a direct result. An unowned lead is not a reporting problem
     * to be surfaced on a dashboard; it is a lead nobody is working.
     *
     * The fallback owner is configurable so it can become a sales manager once
     * there is one. Until then it resolves to the master admin, which matches
     * how the business actually runs today.
     *
     * @param  string  $reason  Recorded on the assignment so the audit trail
     *                          distinguishes "no rules exist yet" from "rules
     *                          exist and none matched" — they need different fixes.
     */
    protected function assignToFallbackOwner(Lead $lead, string $reason): ?User
    {
        $owner = $this->resolveFallbackOwner($lead);

        if (! $owner) {
            return null;
        }

        $this->assignLead($lead, $owner, null, $reason);

        return $owner;
    }

    /**
     * The configured fallback owner, or the organization's master admin.
     *
     * Falls back again to any master admin, because an organization that has
     * not yet been given one still must not swallow a lead silently.
     */
    protected function resolveFallbackOwner(Lead $lead): ?User
    {
        $configuredId = config('crm.fallback_lead_owner_id');

        if ($configuredId) {
            $configured = User::find($configuredId);

            if ($configured) {
                return $configured;
            }
        }

        return User::query()
            ->where('user_type', UserType::MasterAdmin->value)
            ->when($lead->organization_id, fn ($query) => $query
                ->orderByRaw('CASE WHEN organization_id = ? THEN 0 ELSE 1 END', [$lead->organization_id]))
            ->orderBy('id')
            ->first();
    }

    /**
     * Batch reassign leads to a target sales employee with full transaction safety & audit logging.
     */
    public function batchReassign(array $leadIds, int $targetUserId, ?User $actor = null): int
    {
        $targetUser = User::findOrFail($targetUserId);
        $reassignedCount = 0;

        DB::transaction(function () use ($leadIds, $targetUser, $actor, &$reassignedCount) {
            $leads = Lead::whereIn('id', $leadIds)->get();

            foreach ($leads as $lead) {
                if ($lead->assigned_user_id === $targetUser->id) {
                    continue;
                }

                $this->assignLead($lead, $targetUser, $actor, 'batch_reassign', notify: false);
                $reassignedCount++;
            }

            if ($reassignedCount > 0) {
                $this->notifyUser(
                    $targetUser->id,
                    'Batch Leads Assigned',
                    "{$reassignedCount} leads have been assigned to you by ".($actor?->name ?? 'System').'.',
                    NotificationPriority::MEDIUM,
                    ['type' => 'batch_leads_assigned']
                );
            }
        });

        return $reassignedCount;
    }

    /**
     * Complete sales call logging with disposition, duration, notes, and next follow-up.
     */
    public function logCall(Lead $lead, array $data, ?User $user = null): array
    {
        $duration = (int) ($data['duration_seconds'] ?? 60);
        $rawDisposition = $data['disposition'] ?? 'interested';
        $dbDispositionMap = [
            'demo_requested' => 'demo_scheduled',
            'follow_up_required' => 'follow_up',
            'call_back' => 'follow_up',
            'connected' => 'interested',
            'no_answer' => 'busy',
        ];
        $disposition = $dbDispositionMap[$rawDisposition] ?? $rawDisposition;

        $notes = $data['notes'] ?? '';
        $nextFollowupAt = $data['next_followup_at'] ?? null;
        $followupNotes = $data['followup_notes'] ?? $notes;

        // "Call back later" dispositions get an automatic follow-up even if the
        // rep didn't pick a specific date — a rep saying "busy, call back in a
        // couple days" shouldn't have to separately build a reminder.
        if (! $nextFollowupAt && in_array($disposition, ['follow_up', 'busy'], true)) {
            $nextFollowupAt = now()->addDays(2)->toDateTimeString();
            $followupNotes = $followupNotes ?: 'Auto-scheduled follow-up (call back requested).';
        }

        $callLog = $lead->callLogs()->create([
            'user_id' => $user?->id,
            'call_duration_seconds' => $duration,
            'disposition' => $disposition,
            'notes' => $notes,
            'next_followup_at' => $nextFollowupAt,
        ]);

        // Auto-advance stage based on disposition and update follow-up info
        $updateData = [];
        if ($nextFollowupAt) {
            $updateData['followup_notes'] = $followupNotes;
        }

        $currentStatusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
        if ($disposition === 'demo_requested' || $disposition === 'demo_scheduled') {
            $updateData['status'] = LeadStatus::DemoScheduled->value;
        } elseif ($disposition === 'interested' && $currentStatusVal === 'new') {
            $updateData['status'] = LeadStatus::Contacted->value;
        }

        if (! empty($updateData)) {
            $lead->update($updateData);
        }

        // Create Task if follow-up is scheduled
        if ($nextFollowupAt) {
            Task::create([
                'title' => "Call Follow-up: {$lead->company_name}",
                'description' => $followupNotes,
                'type' => TaskType::Call,
                'status' => TaskStatus::Pending,
                'priority' => TaskPriority::High,
                'due_at' => $nextFollowupAt,
                'assigned_user_id' => $lead->assigned_user_id ?? $user?->id,
                'creator_id' => $user?->id,
                'taskable_type' => $lead->getMorphClass(),
                'taskable_id' => $lead->id,
            ]);
        }

        /*
         * `next_followup_at` is derived, never authored — the Task table just
         * grew a new pending commitment (or this call's disposition didn't add
         * one), and the column has to agree with whichever pending Call/FollowUp
         * task is now earliest, not just with the date this one call happened to
         * carry.
         */
        $this->followupSync->sync($lead);

        // Log canonical Activity
        $activity = $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Call,
            'description' => "Phone call logged ({$duration}s) - Disposition: {$disposition}. Notes: {$notes}",
            'properties' => [
                'disposition' => $disposition,
                'duration_seconds' => $duration,
                'notes' => $notes,
                'next_followup_at' => $nextFollowupAt,
            ],
        ]);

        return [
            'callLog' => $callLog,
            'activity' => $activity,
            'lead' => $lead->fresh(['callLogs', 'activities', 'tasks']),
        ];
    }

    /**
     * Mark a follow-up action complete and optionally schedule the next follow-up.
     */
    public function completeFollowup(Lead $lead, array $data, ?User $user = null): Lead
    {
        $outcomeNotes = $data['notes'] ?? 'Follow-up completed.';
        $nextFollowupAt = $data['next_followup_at'] ?? null;
        $nextFollowupNotes = $data['next_followup_notes'] ?? null;

        // 1. Mark existing pending follow-up tasks for this lead as completed
        Task::where('taskable_id', $lead->id)
            ->where('taskable_type', $lead->getMorphClass())
            ->where('status', TaskStatus::Pending->value)
            ->update([
                'status' => TaskStatus::Completed->value,
                'completed_at' => now(),
            ]);

        // 2. Update Lead follow-up notes.
        //
        // `next_followup_at` is no longer written here — it is derived from the
        // task table by LeadFollowupSyncService, so the new task created below
        // is what sets it. Writing both was how the two used to disagree.
        $lead->followup_notes = $nextFollowupNotes;
        $lead->save();

        // 3. Create next follow-up Task if provided
        if ($nextFollowupAt) {
            Task::create([
                'title' => "Follow-up: {$lead->company_name}",
                'description' => $nextFollowupNotes ?? $outcomeNotes,
                'type' => TaskType::FollowUp,
                'status' => TaskStatus::Pending,
                'priority' => TaskPriority::Medium,
                'due_at' => $nextFollowupAt,
                'assigned_user_id' => $lead->assigned_user_id ?? $user?->id,
                'creator_id' => $user?->id,
                'taskable_type' => $lead->getMorphClass(),
                'taskable_id' => $lead->id,
            ]);
        }

        // The mass completion above bypassed model events, so reconcile the
        // cache once, after the replacement task exists.
        app(LeadFollowupSyncService::class)->sync($lead);

        // 4. Log canonical Activity
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::FollowUp,
            'description' => "Follow-up completed: {$outcomeNotes}".($nextFollowupAt ? ". Next follow-up scheduled for {$nextFollowupAt}" : ''),
            'properties' => [
                'outcome_notes' => $outcomeNotes,
                'next_followup_at' => $nextFollowupAt,
            ],
        ]);

        return $lead->fresh(['activities', 'tasks']);
    }

    /**
     * Generate deterministic notifications for due/overdue follow-ups and approaching demos.
     */
    public function checkAndGenerateReminders(?int $organizationId = null): int
    {
        $now = now();
        $count = 0;

        // 1. Overdue or Due Follow-ups (due <= 30 mins from now or past)
        $leadsWithDueFollowups = Lead::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<=', $now->copy()->addMinutes(30))
            ->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost])
            ->get();

        foreach ($leadsWithDueFollowups as $lead) {
            $isOverdue = Carbon::parse($lead->next_followup_at)->isPast();
            $type = $isOverdue ? 'followup_overdue' : 'followup_due';
            $title = $isOverdue ? "Overdue Follow-up: {$lead->company_name}" : "Follow-up Due: {$lead->company_name}";

            // Prevent duplicate notifications today
            $exists = EnterpriseNotification::where('notifiable_id', $lead->assigned_user_id)
                ->where('notifiable_type', User::class)
                ->whereDate('created_at', Carbon::today())
                ->where('data->metadata->lead_id', $lead->id)
                ->where('data->metadata->type', $type)
                ->exists();

            if (! $exists) {
                $this->notifyUser(
                    $lead->assigned_user_id,
                    $title,
                    "Follow-up action for {$lead->company_name} ({$lead->contact_name}): {$lead->followup_notes}",
                    $isOverdue ? NotificationPriority::HIGH : NotificationPriority::MEDIUM,
                    ['type' => $type, 'lead_id' => $lead->id]
                );
                $count++;
            }
        }

        // 2. Demos Approaching (scheduled in next 24 hours)
        $approachingDemos = Demo::query()
            ->whereBetween('scheduled_at', [$now, $now->copy()->addHours(24)])
            ->where('status', 'scheduled')
            ->with('lead')
            ->get();

        foreach ($approachingDemos as $demo) {
            $lead = $demo->lead;
            $userId = $demo->presenter_id ?? $lead?->assigned_user_id;

            if ($userId && $lead) {
                $exists = EnterpriseNotification::where('notifiable_id', $userId)
                    ->where('notifiable_type', User::class)
                    ->whereDate('created_at', Carbon::today())
                    ->where('data->metadata->demo_id', $demo->id)
                    ->where('data->metadata->type', 'demo_approaching')
                    ->exists();

                if (! $exists) {
                    $this->notifyUser(
                        $userId,
                        "Demo Approaching: {$lead->company_name}",
                        "Demo scheduled at {$demo->scheduled_at->format('M d, H:i')} with {$lead->contact_name}",
                        NotificationPriority::HIGH,
                        ['type' => 'demo_approaching', 'demo_id' => $demo->id, 'lead_id' => $lead->id]
                    );
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Send WhatsApp message from inside CRM console.
     */
    public function sendWhatsAppMessage(Lead $lead, string $body, ?string $templateName = null, ?User $user = null)
    {
        /*
         * One WhatsApp system.
         *
         * This wrote a row to `lead_whatsapp_messages` — a table the deal page
         * never rendered — and an activity saying the message had gone out, so
         * a rep could believe they had contacted somebody who received nothing.
         * It goes through the Marketing account now, and says so when it cannot.
         */
        return app(DealConversationService::class)->sendAutomated($lead, $body, $templateName);
    }

    /**
     * Log a communication entry against a lead.
     */
    public function logCommunication(Lead $lead, array $data, ?User $user = null): Activity
    {
        $typeMap = [
            'call' => ActivityType::Call,
            'email' => ActivityType::Email,
            'whatsapp' => ActivityType::WhatsApp,
            'meeting' => ActivityType::Meeting,
            'note' => ActivityType::Note,
        ];

        $activityType = $typeMap[$data['type'] ?? 'call'] ?? ActivityType::Call;

        if (($data['type'] ?? 'call') === 'call') {
            $this->logCall($lead, [
                'duration_seconds' => $data['duration_seconds'] ?? 60,
                'disposition' => $data['disposition'] ?? 'connected',
                'notes' => $data['summary'],
                'next_followup_at' => $data['next_followup_at'] ?? null,
            ], $user);
        }

        if (! empty($data['next_followup_at'])) {
            $lead->update([
                'next_followup_at' => $data['next_followup_at'],
                'followup_notes' => $data['summary'],
            ]);
        }

        return $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => $activityType,
            'description' => Str::title($data['type'] ?? 'Call')." logged: {$data['summary']}",
            'properties' => [
                'type' => $data['type'] ?? 'call',
                'sentiment' => $data['sentiment'] ?? 'neutral',
                'summary' => $data['summary'],
                'next_followup_at' => $data['next_followup_at'] ?? null,
            ],
        ]);
    }

    /**
     * Find a duplicate lead within organization scope by normalized phone or email.
     */
    public function findDuplicateLead(?int $organizationId, ?string $phone, ?string $email, ?int $excludeLeadId = null, ?string $companyName = null): ?Lead
    {
        $normalized = $phone ? PhoneNormalizer::normalize($phone) : null;
        $email = $email ? strtolower(trim($email)) : null;
        $companyName = $companyName ? trim($companyName) : null;

        // Nothing identifying to match on — treat as unique rather than
        // returning an arbitrary first row.
        if (! $normalized && ! $email && ! $companyName) {
            return null;
        }

        /*
         * Fail closed.
         *
         * This used to skip the tenant predicate entirely when the organization
         * could not be resolved, and the endpoint above it returns the matched
         * lead's company name, contact name, stage and owner — so a null tenant
         * handed another customer's pipeline to whoever was typing in the create
         * form. A duplicate check that cannot establish whose book it is
         * searching has no book to search.
         */
        if (! $organizationId) {
            return null;
        }

        $query = Lead::query()->where('organization_id', $organizationId);

        if ($excludeLeadId) {
            $query->where('id', '!=', $excludeLeadId);
        }

        $query->where(function ($q) use ($normalized, $email, $companyName) {
            if ($normalized) {
                /*
                 * Both columns, deliberately.
                 *
                 * `normalized_phone` is the column this is supposed to match on,
                 * but it is null on most rows and, where it is populated, does
                 * not always hold what `PhoneNormalizer::normalize()` produces
                 * today — some rows carry a country-prefixed form. Until that
                 * column is backfilled, dropping the raw-`phone` fallback stops
                 * the check finding duplicates it used to find, which is a worse
                 * failure than an extra OR branch. Both columns are indexed, so
                 * the branch costs a second lookup rather than a scan.
                 */
                $q->orWhere('normalized_phone', $normalized)
                    ->orWhere('phone', $normalized);
            }
            if ($email) {
                // Matches `leads_email_lower_index`, so this is a lookup rather
                // than the full scan it was before that index existed.
                $q->orWhereRaw('LOWER(email) = ?', [$email]);
            }
            if ($companyName) {
                // Likewise `leads_company_name_lower_index`.
                $q->orWhereRaw('LOWER(company_name) = ?', [strtolower($companyName)]);
            }
        });

        // Prefer the strongest signal: a phone/email match is a near-certain
        // duplicate, a company-name match alone is only a likely one.
        return $query->orderByRaw(
            'CASE WHEN normalized_phone = ? THEN 0 WHEN LOWER(email) = ? THEN 1 ELSE 2 END',
            [$normalized ?? '', $email ?? '']
        )->first();
    }

    /**
     * Detect a duplicate and, when found, leave an audit trail on the new lead
     * so the warning survives beyond the flash message.
     */
    public function flagDuplicate(Lead $lead, ?Lead $duplicate, ?User $user = null): void
    {
        if (! $duplicate) {
            return;
        }

        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::SystemUpdate,
            'description' => "Possible duplicate of Lead #{$duplicate->id} ({$duplicate->company_name}). Review before working this lead.",
            'properties' => [
                'duplicate_lead_id' => $duplicate->id,
                'duplicate_company_name' => $duplicate->company_name,
            ],
        ]);
    }

    /**
     * Create a new Lead with normalized phone, slug, activity logging, and duplicate warning metadata.
     */
    /**
     * Put the note the rep typed somewhere it survives.
     *
     * The form field is called `notes` and the column is `followup_notes`;
     * there is no `notes` column, so every note written on a deal — on the way
     * in and on the way through — was validated and then silently dropped.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function foldNotesIntoFollowup(array $data): array
    {
        if (! array_key_exists('notes', $data)) {
            return $data;
        }

        $data['followup_notes'] ??= $data['notes'];
        unset($data['notes']);

        return $data;
    }

    public function createLead(array $data, ?User $user = null): array
    {        /*
         * The contact and the branches are their own records, not lead
         * columns, so they are lifted out before the lead is written.
         */
        $contactData = $data['contact'] ?? null;
        $branchData = $data['branches'] ?? null;
        unset($data['contact'], $data['branches']);

        $data = $this->foldNotesIntoFollowup($data);

        /*
         * The singular specialty column is kept in step with the plural.
         *
         * Everything written before this form — the public intake, the
         * workspace territory label, NextBestActionService — reads the
         * singular, so it keeps carrying the first specialty rather than
         * becoming stale the moment the new form is used.
         */
        if (! empty($data['healthcare_specialties']) && is_array($data['healthcare_specialties'])) {
            $data['healthcare_specialty'] ??= $data['healthcare_specialties'][0] ?? null;
        }

        if (! empty($data['phone'])) {
            $data['normalized_phone'] = PhoneNormalizer::normalize($data['phone']);
        }

        if (empty($data['slug']) && ! empty($data['company_name'])) {
            $data['slug'] = Str::slug($data['company_name']).'-'.Str::random(5);
        }

        $organizationId = $data['organization_id'] ?? $user?->resolveOrganizationId();
        if ($organizationId) {
            $data['organization_id'] = $organizationId;
        }

        $duplicate = $this->findDuplicateLead(
            $data['organization_id'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            null,
            $data['company_name'] ?? null
        );

        // This method composes its own richer creation activity below, so the
        // observer's generic one is suppressed — otherwise a new lead is born
        // twice on its own timeline.
        LeadObserver::$suppressCreationActivity = true;

        try {
            $lead = Lead::create($data);
        } finally {
            LeadObserver::$suppressCreationActivity = false;
        }

        $this->flagDuplicate($lead, $duplicate, $user);

        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::RecordCreated,
            'description' => "Lead created: {$lead->company_name} ({$lead->contact_name})",
            'properties' => [
                'source' => $lead->source,
                'status' => (string) ($lead->status->value ?? $lead->status),
                'marketing_contact_id' => $lead->marketing_contact_id,
                'marketing_campaign_id' => $lead->marketing_campaign_id,
            ],
        ]);

        $this->syncPrimaryContact($lead, $contactData);
        $this->syncLeadBranches($lead, $branchData);
        $this->ensureMainBranch($lead);

        /*
         * Auto-assignment is not called here any more. `LeadObserver::created()`
         * does it for every lead however it was made, so by the time this line
         * used to run the lead already has an owner and the call was a wasted
         * round trip.
         */

        return [
            'lead' => $lead->fresh(['assignedUser', 'organization', 'activities', 'contacts', 'branches']),
            'duplicate_detected' => $duplicate !== null,
            'duplicate_lead_id' => $duplicate?->id,
            'duplicate_company_name' => $duplicate?->company_name,
        ];
    }

    /**
     * Record the person the rep actually spoke to.
     *
     * The lead's own contact_name/email/phone stay authoritative for the list,
     * the search and the duplicate check — every existing query reads them —
     * and this writes the same person as a real Contact so the designation,
     * decision role and WhatsApp number have somewhere to live. The two are
     * written from one payload, so they cannot drift apart at creation.
     *
     * @param  array<string, mixed>|null  $contact
     */
    protected function syncPrimaryContact(Lead $lead, ?array $contact): ?Contact
    {
        $name = trim((string) $lead->contact_name);

        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        /*
         * An edit that renames the contact but says nothing about their
         * WhatsApp number must not erase it, so the existing row supplies the
         * defaults for anything this payload leaves out.
         */
        $existing = Contact::query()->where('lead_id', $lead->id)->where('is_primary', true)->first();

        /*
         * A contact belongs to an organization, and some older deals have none.
         * Those rows are a known data problem awaiting a controlled cleanup —
         * until then, renaming one must not throw. There is nothing to keep in
         * step when there is no contact row to keep.
         */
        if ($lead->organization_id === null && $existing === null) {
            return null;
        }

        return Contact::updateOrCreate(
            ['lead_id' => $lead->id, 'is_primary' => true],
            [
                'organization_id' => $lead->organization_id,
                'first_name' => $parts[0],
                'last_name' => $parts[1] ?? null,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'whatsapp' => $contact['whatsapp'] ?? $existing?->whatsapp,
                'job_title' => $contact['job_title'] ?? $existing?->job_title,
                'decision_role' => $contact['decision_role'] ?? $existing?->decision_role,
            ]
        );
    }

    /**
     * Replace the branches recorded against a prospect.
     *
     * Passing null leaves whatever is there alone — an update that says
     * nothing about branches should not delete them. Passing an array is the
     * full set, because the form edits them as one list.
     *
     * `branch_count` is kept in step when branches are given, so the headline
     * figure and the detail can never contradict each other.
     *
     * @param  list<array<string, mixed>>|null  $branches
     */
    /**
     * Every lead has a main site, even before anybody has asked about it.
     *
     * The intake form is answered on a phone call and cannot produce a branch
     * list, so `syncLeadBranches()` was handed null and returned immediately —
     * leaving `leads.branch_count` defaulted to 1 while `lead_branches` held
     * nothing. Fifty-four leads, all claiming one site, fifteen with a row to
     * show for it. The footprint on Deal 360 counted `branches.length`, so the
     * other thirty-nine reported an estate of zero.
     *
     * The bootstrapped row carries only what intake actually knows: the
     * organisation's own name and city. Capacity stays null until somebody asks
     * — that is what the discovery form is for.
     *
     * Create-time only, and idempotent. An update that omits `branches` must
     * not resurrect a site somebody deliberately removed.
     */
    protected function ensureMainBranch(Lead $lead): void
    {
        if ($lead->branches()->exists()) {
            return;
        }

        $lead->branches()->create([
            'name' => $lead->company_name ?: ($lead->contact_name ?: 'Main site'),
            'is_main' => true,
            'city' => $lead->city,
            'area' => $lead->area,
            'state' => $lead->state,
            'country' => $lead->country,
            'postal_code' => $lead->postal_code,
            'position' => 0,
        ]);
    }

    protected function syncLeadBranches(Lead $lead, ?array $branches): void
    {
        if ($branches === null) {
            return;
        }

        // `delete()`, not `forceDelete()` — LeadBranch is no longer soft-deleted.
        $lead->branches()->delete();

        $rows = collect($branches)
            ->filter(fn ($branch) => filled($branch['name'] ?? null))
            ->values();

        if ($rows->isEmpty()) {
            return;
        }

        /*
         * Exactly one main branch. If the rep marked none, the first is taken
         * as the main site — a list of branches with no head is not a thing
         * anybody means.
         */
        $hasMain = $rows->contains(fn ($branch) => filter_var($branch['is_main'] ?? false, FILTER_VALIDATE_BOOL));

        $rows->each(function (array $branch, int $index) use ($lead, $hasMain) {
            $lead->branches()->create([
                'name' => $branch['name'],
                'is_main' => $hasMain
                    ? filter_var($branch['is_main'] ?? false, FILTER_VALIDATE_BOOL)
                    : $index === 0,
                'address' => $branch['address'] ?? null,
                'area' => $branch['area'] ?? null,
                'city' => $branch['city'] ?? null,
                'state' => $branch['state'] ?? null,
                'country' => $branch['country'] ?? null,
                'postal_code' => $branch['postal_code'] ?? null,
                'contact_name' => $branch['contact_name'] ?? null,
                'phone' => $branch['phone'] ?? null,
                'email' => $branch['email'] ?? null,

                /*
                 * What the site can handle.
                 *
                 * `??` rather than `?? 0`: these are nullable on purpose. A
                 * physiotherapy chain has no beds and a solo clinic has no
                 * theatres, and `DealDomainPresenter` renders "nobody asked"
                 * and "the answer was none" differently — writing zero here
                 * would collapse that distinction at the point of capture.
                 */
                'doctor_count' => $branch['doctor_count'] ?? null,
                'bed_count' => $branch['bed_count'] ?? null,
                'treatment_station_count' => $branch['treatment_station_count'] ?? null,
                'operation_theatre_count' => $branch['operation_theatre_count'] ?? null,

                'position' => $index,
            ]);
        });

        /*
         * The headline figure follows the list, and never drops below one.
         *
         * A practice always operates at least one site: recording no branch
         * rows means the sites have not been captured, not that there are
         * none. Writing a literal count let an empty list say "0 branches",
         * which is both false and below the `min:1` the field is validated
         * against everywhere else.
         */
        $lead->forceFill(['branch_count' => max(1, $rows->count())])->saveQuietly();
    }

    /** The lead's stage as a plain string, however it happens to be cast. */
    protected function stageValue(Lead $lead): string
    {
        return $lead->status instanceof \BackedEnum ? (string) $lead->status->value : (string) $lead->status;
    }

    /**
     * Update an existing Lead with activity audit logging and phone normalization.
     */
    /**
     * Human-readable labels for fields whose changes are worth a dedicated
     * audit-log line. Status and assignment changes are already logged with
     * richer, domain-specific messages by LeadObserver, so they're excluded
     * here to avoid duplicate log entries.
     *
     * @var array<string, string>
     */
    protected const AUDITED_FIELD_LABELS = [
        'company_name' => 'Company Name',
        'contact_name' => 'Contact Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'address' => 'Address',
        'city' => 'City',
        'state' => 'State',
        'country' => 'Country',
        'postal_code' => 'Postal Code',
        'source' => 'Lead Source',
        'organization_type' => 'Organization Type',
        'healthcare_specialty' => 'Specialty',
        'organization_size' => 'Organization Size',
        'branch_count' => 'Branch Count',
        'expected_users' => 'Expected Users',
        'expected_mrr' => 'Expected MRR',
        'expected_arr' => 'Expected ARR',
        'next_followup_at' => 'Next Follow-up',
        'followup_notes' => 'Follow-up Notes',
        'current_software' => 'Current Software',
        'deployment_preference' => 'Deployment Preference',
    ];

    public function updateLead(Lead $lead, array $data, ?User $user = null): Lead
    {
        /*
         * The contact and the branches are their own records, exactly as on
         * creation. Absent keys mean "this edit said nothing about them" and
         * leave them alone — a section that saves only its own fields must not
         * wipe the sections it never showed.
         */
        $contactData = array_key_exists('contact', $data) ? $data['contact'] : null;
        $branchData = array_key_exists('branches', $data) ? $data['branches'] : null;
        unset($data['contact'], $data['branches']);

        $data = $this->foldNotesIntoFollowup($data);

        /* The legacy singular follows the plural, as it does on creation. */
        if (! empty($data['healthcare_specialties']) && is_array($data['healthcare_specialties'])) {
            $data['healthcare_specialty'] = $data['healthcare_specialties'][0] ?? null;
        }

        if (isset($data['phone']) && $data['phone'] !== $lead->phone) {
            $data['normalized_phone'] = PhoneNormalizer::normalize($data['phone']);
        }

        /*
         * This endpoint edits reference data, not the pipeline. `status` rode
         * along in the request schema with none of the legality checks
         * `transitionStage()` exists to enforce — a plain profile save could
         * drag a Won deal (a live, provisioned customer) back to Contacted,
         * or silently un-lose a deal with no reason recorded. Any change in
         * status here is held to the exact same rule as the dedicated
         * transition endpoint.
         */
        if (array_key_exists('status', $data)) {
            $currentStatus = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
            $targetStatus = is_object($data['status']) && isset($data['status']->value) ? $data['status']->value : (string) $data['status'];

            if ($currentStatus !== $targetStatus) {
                $blocked = app(DealStageService::class)->blockReason($lead, $targetStatus);

                if ($blocked !== null) {
                    throw new IllegalStageTransition($currentStatus, $targetStatus, $blocked);
                }
            }
        }

        /*
         * Same reasoning for assignment: a deal must stay with a person in
         * the organisation that owns it. Only the cross-tenant edge is
         * refused here — who on your own team you may hand a deal to is a
         * role question this endpoint already leaves alone, `assign()`'s
         * batch sibling included.
         */
        if (! empty($data['assigned_user_id']) && $lead->organization_id) {
            $targetOrgId = User::find((int) $data['assigned_user_id'])?->resolveOrganizationId();

            if ($targetOrgId && $targetOrgId !== (int) $lead->organization_id) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => 'That person is not on your team.',
                ]);
            }
        }

        /*
         * The values as they stand, so the recorder can report what a field
         * moved from rather than only what it moved to.
         */
        $before = $lead->getAttributes();
        $branchesBefore = $branchData !== null ? $lead->branches()->count() : null;
        $contactBefore = $contactData !== null
            ? $lead->contacts()->where('is_primary', true)->first()?->only(['whatsapp', 'job_title', 'decision_role'])
            : null;

        $lead->update($data);

        /*
         * The contact row also carries the lead's own name, email and phone.
         * When a section corrects one of those it must follow, or the deal and
         * the person on it start disagreeing about who to call.
         */
        $identityTouched = (bool) array_intersect(['contact_name', 'email', 'phone'], array_keys($data));

        if ($contactData !== null || $identityTouched) {
            $this->syncPrimaryContact($lead->fresh(), $contactData);
        }

        $this->syncLeadBranches($lead, $branchData);

        $this->activity->fieldChanges($lead, $before, $data, $user);

        /*
         * The contact and the branches are their own records, so a field diff
         * would be noise — but changing them is still a change to the deal,
         * and it used to leave nothing behind at all.
         */
        if ($contactData !== null) {
            $contactAfter = $lead->contacts()->where('is_primary', true)->first()?->only(['whatsapp', 'job_title', 'decision_role']);

            if ($contactBefore !== $contactAfter) {
                $this->activity->relatedRecord(
                    $lead,
                    ActivityType::RecordUpdated,
                    'Primary contact updated —',
                    (string) $lead->contact_name,
                    ['contact' => $contactAfter],
                    $user,
                );
            }
        }

        if ($branchesBefore !== null) {
            $branchesAfter = $lead->branches()->count();

            if ($branchesBefore !== $branchesAfter) {
                $this->activity->relatedRecord(
                    $lead,
                    ActivityType::RecordUpdated,
                    'Branches updated —',
                    "{$branchesBefore} → {$branchesAfter} sites",
                    ['from' => $branchesBefore, 'to' => $branchesAfter],
                    $user,
                );
            }
        }

        return $lead;
    }

    /**
     * Schedule a new product Demo for a Lead.
     */
    /**
     * Find a demo that would overlap the given presenter's slot.
     *
     * Two demos clash when their [start, start+duration) windows intersect for
     * the same presenter. Only live states count — a cancelled, completed,
     * no-show or superseded (rescheduled) demo no longer occupies the diary.
     */
    public function findConflictingDemo(?int $presenterId, Carbon $scheduledAt, int $durationMinutes, ?int $excludeDemoId = null): ?Demo
    {
        if (! $presenterId) {
            return null;
        }

        $newStart = $scheduledAt->copy();
        $newEnd = $scheduledAt->copy()->addMinutes($durationMinutes);

        return Demo::query()
            ->where('presenter_id', $presenterId)
            ->whereIn('status', [DemoStatus::Scheduled, DemoStatus::Confirmed])
            ->when($excludeDemoId, fn ($q) => $q->where('id', '!=', $excludeDemoId))
            ->whereDate('scheduled_at', $newStart->toDateString())
            ->get()
            ->first(function (Demo $existing) use ($newStart, $newEnd) {
                $existingStart = $existing->scheduled_at->copy();
                $existingEnd = $existingStart->copy()->addMinutes((int) ($existing->duration_minutes ?: 45));

                return $newStart->lt($existingEnd) && $newEnd->gt($existingStart);
            });
    }

    /**
     * Assert the presenter is free, or explain the clash.
     *
     * @throws InvalidArgumentException
     */
    public function assertPresenterAvailable(?int $presenterId, Carbon $scheduledAt, int $durationMinutes, ?int $excludeDemoId = null): void
    {
        $conflict = $this->findConflictingDemo($presenterId, $scheduledAt, $durationMinutes, $excludeDemoId);

        if (! $conflict) {
            return;
        }

        $presenterName = $conflict->presenter?->name ?? 'This presenter';
        $clashingLead = $conflict->lead?->company_name ?? "Demo #{$conflict->id}";

        throw new InvalidArgumentException(
            "{$presenterName} already has a demo with {$clashingLead} at ".
            $conflict->scheduled_at->format('d M Y, g:i A').'. Choose another time or presenter.'
        );
    }

    public function scheduleDemo(Lead $lead, array $data, ?User $user = null): Demo
    {
        $presenterId = $data['presenter_id'] ?? $lead->assigned_user_id ?? $user?->id;
        $scheduledAt = Carbon::parse($data['scheduled_at']);
        $duration = (int) ($data['duration_minutes'] ?? 45);

        $this->assertPresenterAvailable($presenterId, $scheduledAt, $duration);

        /*
         * Platform and mode are kept in step here rather than trusted from the
         * request, because they overlap on `in_person` and a form that sends
         * both can send them contradicting each other. The platform is the
         * finer-grained answer, so it wins and the mode is derived from it.
         */
        $platform = $data['meeting_platform'] ?? null;

        if (! array_key_exists($platform, Demo::PLATFORMS)) {
            // No platform given: infer it from the mode the caller did send, so
            // existing callers keep working unchanged.
            $platform = ($data['demo_mode'] ?? 'online') === 'in_person'
                ? Demo::PLATFORM_IN_PERSON
                : Demo::PLATFORM_GOOGLE_MEET;
        }

        $demoMode = $platform === Demo::PLATFORM_IN_PERSON ? 'in_person' : 'online';

        $location = $data['location'] ?? null;
        $meetingLink = $data['meeting_link'] ?? null;
        $meetingId = $data['meeting_id'] ?? null;
        $passcode = $data['passcode'] ?? null;
        $notes = $data['notes'] ?? null;

        $demo = Demo::create([
            'lead_id' => $lead->id,
            'presenter_id' => $presenterId,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => $duration,
            'demo_mode' => $demoMode,
            'meeting_platform' => $platform,
            'location' => $location,
            'status' => DemoStatus::Scheduled,
            'confirmation_status' => DemoConfirmationStatus::Pending,
            'meeting_link' => $meetingLink,
            'meeting_id' => $meetingId,
            'passcode' => $passcode,
            'notes' => $notes,
        ]);

        // Auto transition Lead stage to DemoScheduled via canonical mechanism
        $this->transitionStage($lead, LeadStatus::DemoScheduled->value, $user, "Demo scheduled for {$scheduledAt->format('M d, Y h:i A')}");

        // Assigned employee reminder task
        Task::create([
            'title' => "Demo Scheduled: {$lead->company_name}",
            'description' => "Prepare and conduct {$demoMode} demo. Link/Location: ".($meetingLink ?? $location ?? 'N/A').". Notes: {$notes}",
            'type' => TaskType::Demo,
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::High,
            'due_at' => $scheduledAt,
            'assigned_user_id' => $presenterId,
            'creator_id' => $user?->id,
            'taskable_type' => $lead->getMorphClass(),
            'taskable_id' => $lead->id,
        ]);

        // Send employee notification
        if ($presenterId) {
            $this->notifyUser(
                $presenterId,
                'New Demo Scheduled',
                "Product demo for {$lead->company_name} scheduled for {$scheduledAt->format('M d, Y h:i A')}.",
                NotificationPriority::HIGH,
                ['type' => 'demo_scheduled', 'demo_id' => $demo->id, 'lead_id' => $lead->id]
            );
        }

        // Note: the client WhatsApp reminder is deliberately NOT sent here —
        // it's meant to land on the morning of the demo, not at booking time.
        // See SendDemoDayRemindersJob, which calls sendClientDemoReminder().

        // Log canonical Activity
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Demo,
            'description' => "Demo scheduled for {$scheduledAt->format('M d, Y h:i A')} ({$duration}m, {$demoMode}).".($notes ? "\nNotes: {$notes}" : ''),
            'properties' => [
                'demo_id' => $demo->id,
                'scheduled_at' => $scheduledAt->toDateTimeString(),
                'presenter_id' => $presenterId,
                'demo_mode' => $demoMode,
                'meeting_link' => $meetingLink,
            ],
        ]);

        return $demo;
    }

    /**
     * Send client WhatsApp/Email reminder respecting Marketing M-8/M-9 governance rules.
     */
    public function sendClientDemoReminder(Demo $demo): bool
    {
        $lead = $demo->lead;
        if (! $lead) {
            return false;
        }

        // Halts automation if Lead is Won or Lost
        $statusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
        if ($statusVal === LeadStatus::Won->value || $statusVal === LeadStatus::Lost->value) {
            return false;
        }

        $orgId = $lead->organization_id;

        // Governance Check 1: Marketing Contact Opt-Out & Suppression (M-8/M-9)
        $contact = $lead->marketingContact ?? MarketingContact::where('organization_id', $orgId)
            ->where('normalized_phone', $lead->normalized_phone)
            ->first();

        if ($contact && in_array($contact->marketing_status, ['opted_out', 'suppressed'])) {
            return false;
        }

        // Governance Check 2: Emergency Freeze Kill-Switch & Quiet Hours
        $settingsService = app(MarketingSettingsService::class);
        if ($settingsService->isEmergencyFreezeActive($orgId)) {
            return false;
        }

        if ($settingsService->isInQuietHours($orgId)) {
            return false;
        }

        if (! $lead->phone) {
            return false;
        }

        // Dispatch the approved 'demo_reminder_client' template via the real
        // Meta WhatsApp Cloud API pipeline, falling back to an internal-only
        // log (never a fake "sent" status) when no approved template/account
        // is configured yet for this organization.
        $scheduledAtStr = $demo->scheduled_at->format('M d, Y h:i A');
        $linkStr = $demo->meeting_link ?? $demo->location ?? 'Online Meeting';

        $result = $this->sendCrmWhatsAppTemplate(
            $orgId,
            'demo_reminder_client',
            $lead->phone,
            [$lead->contact_name, $scheduledAtStr, $linkStr],
            "demo:{$demo->id}:reminder:".$demo->scheduled_at->format('Ymd')
        );

        $lead->activities()->create([
            'user_id' => null,
            'type' => ActivityType::WhatsApp,
            'description' => $result['success']
                ? "Automated client demo reminder sent via WhatsApp: {$scheduledAtStr}"
                : "Client demo reminder NOT sent ({$this->describeCrmSendFailure($result)}) — logged only. Scheduled for {$scheduledAtStr}.",
            'properties' => [
                'demo_id' => $demo->id,
                'scheduled_at' => $scheduledAtStr,
                'send_result' => $result['success'] ? 'sent' : ($result['error'] ?? 'failed'),
            ],
        ]);

        return $result['success'];
    }

    /**
     * Send a rep's "today's demos" WhatsApp digest, plus an in-app notification.
     */
    public function sendRepDemoDigest(User $presenter, Collection $demos): array
    {
        $count = $demos->count();
        $firstTime = $demos->first()->scheduled_at->format('h:i A');
        $summary = $count === 1
            ? "You have 1 demo today at {$firstTime}."
            : "You have {$count} demos today, starting at {$firstTime}.";

        $result = $presenter->phone
            ? $this->sendCrmWhatsAppTemplate(
                $presenter->organization_id,
                'demo_reminder_rep',
                $presenter->phone,
                [$presenter->name, (string) $count, $firstTime],
                "rep_digest:{$presenter->id}:".now()->format('Ymd')
            )
            : ['success' => false, 'error' => 'no_phone_on_file'];

        $this->notifyUser(
            $presenter->id,
            "Today's Demos",
            $summary,
            NotificationPriority::MEDIUM,
            ['type' => 'demo_day_digest', 'demo_ids' => $demos->pluck('id')->all()]
        );

        return $result;
    }

    /**
     * A rep's whole morning in one message.
     *
     * Separate from `sendRepDemoDigest()`, which says only how many demos there
     * are. This is the three things a rep has to act on today — demos to run,
     * trials that need a push, follow-ups already late — in the order they
     * matter, because three notifications at 08:30 is three things to dismiss
     * and one thing to read.
     *
     * A rep with an empty day is not messaged at all. An agenda that says
     * "nothing today" trains people to stop opening it.
     *
     * @param  array{demos:int, first_demo_at:?string, pending_trials:int, overdue_followups:int}  $agenda
     * @return array{success:bool, error?:string}
     */
    public function sendRepDailyAgenda(User $rep, array $agenda): array
    {
        $demos = (int) ($agenda['demos'] ?? 0);
        $trials = (int) ($agenda['pending_trials'] ?? 0);
        $overdue = (int) ($agenda['overdue_followups'] ?? 0);

        if ($demos === 0 && $trials === 0 && $overdue === 0) {
            return ['success' => false, 'error' => 'nothing_to_report'];
        }

        $lines = [];

        if ($demos > 0) {
            $at = $agenda['first_demo_at'] ?? null;
            $lines[] = $demos === 1
                ? ($at ? "1 demo, at {$at}" : '1 demo')
                : ($at ? "{$demos} demos, first at {$at}" : "{$demos} demos");
        }

        if ($trials > 0) {
            $lines[] = $trials === 1 ? '1 trial needs attention' : "{$trials} trials need attention";
        }

        if ($overdue > 0) {
            $lines[] = $overdue === 1 ? '1 overdue follow-up' : "{$overdue} overdue follow-ups";
        }

        $summary = implode(' · ', $lines);

        /*
         * The in-app notification is the one that always lands. WhatsApp is best
         * effort — a rep with no number on file, or a tenant with no approved
         * template, still gets their agenda when they open the product.
         */
        $this->notifyUser(
            $rep->id,
            'Your day',
            $summary,
            NotificationPriority::MEDIUM,
            [
                'type' => 'rep_daily_agenda',
                'demos' => $demos,
                'pending_trials' => $trials,
                'overdue_followups' => $overdue,
            ]
        );

        if (! $rep->phone) {
            return ['success' => false, 'error' => 'no_phone_on_file'];
        }

        return $this->sendCrmWhatsAppTemplate(
            $rep->organization_id,
            'rep_daily_agenda',
            $rep->phone,
            [$rep->name, $summary],
            // One agenda per rep per day, however many times the job runs.
            "rep_agenda:{$rep->id}:".now()->format('Ymd')
        );
    }

    /**
     * Send a client a WhatsApp request to rate/give feedback on a completed demo.
     */
    public function sendDemoFeedbackRequest(Demo $demo): bool
    {
        $lead = $demo->lead;
        if (! $lead || ! $lead->phone) {
            return false;
        }

        $result = $this->sendCrmWhatsAppTemplate(
            $lead->organization_id,
            'demo_feedback_request',
            $lead->phone,
            [$lead->contact_name],
            "demo:{$demo->id}:feedback_request"
        );

        $lead->activities()->create([
            'user_id' => null,
            'type' => ActivityType::WhatsApp,
            'description' => $result['success']
                ? 'Demo feedback request sent to client via WhatsApp'
                : "Demo feedback request NOT sent ({$this->describeCrmSendFailure($result)}) — logged only.",
            'properties' => ['demo_id' => $demo->id],
        ]);

        return $result['success'];
    }

    /**
     * Shared real-send layer for CRM-initiated WhatsApp template messages,
     * built on the same Meta Cloud API pipeline Marketing campaigns use
     * (`WhatsAppMessageService`). Requires the organization to have an
     * approved `MarketingWhatsAppTemplate` with the given name — when one
     * isn't configured yet, this fails gracefully (never a fake "sent")
     * rather than blocking the caller.
     *
     * @param  array<int, string>  $textParams  Ordered {{1}}, {{2}}, ... body variables.
     * @return array{success: bool, error?: string, message_id?: mixed}
     */
    protected function sendCrmWhatsAppTemplate(?int $organizationId, string $templateName, ?string $phone, array $textParams, string $idempotencyKey): array
    {
        if (! $organizationId || ! $phone) {
            return ['success' => false, 'error' => 'missing_organization_or_phone'];
        }

        $template = MarketingWhatsAppTemplate::where('organization_id', $organizationId)
            ->where('name', $templateName)
            ->approved()
            ->first();

        if (! $template) {
            return ['success' => false, 'error' => 'template_not_configured'];
        }

        $account = MarketingWhatsAppAccount::find($template->whatsapp_account_id);
        if (! $account) {
            return ['success' => false, 'error' => 'account_not_configured'];
        }

        $components = [];
        if (! empty($textParams)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $textParams),
            ];
        }

        return app(WhatsAppMessageService::class)->sendOutboundTemplateMessage(
            $account,
            $phone,
            $template->name,
            $template->language,
            $components,
            $idempotencyKey
        );
    }

    protected function describeCrmSendFailure(array $result): string
    {
        return match ($result['error'] ?? null) {
            'template_not_configured' => "approve a 'demo_reminder_client'-style template in Marketing > Templates",
            'account_not_configured' => 'the template\'s WhatsApp account is not configured',
            'missing_organization_or_phone' => 'no phone number on file',
            default => $result['error'] ?? 'send failed',
        };
    }

    /**
     * Send a real, bell-visible in-app notification via the shared
     * Notification Engine (`EnterpriseNotification` — the model the actual
     * notification bell UI reads). Replaces the old pattern of writing to
     * `App\Models\Notification`, which nothing renders. Never throws — a
     * notification failure should never block the business action that
     * triggered it.
     */
    /**
     * Public because {@see TrialLifecycleService} sends through
     * it too. One notification pipeline beats a second one that drifts.
     */
    /**
     * @param  array<int, NotificationActionDTO>  $actions
     */
    public function notifyUser(
        int $userId,
        string $title,
        string $message,
        NotificationPriority $priority = NotificationPriority::NORMAL,
        array $metadata = [],
        NotificationCategory $category = NotificationCategory::CRM,
        array $actions = [],
    ): void {
        try {
            $user = User::find($userId);
            if (! $user) {
                return;
            }

            app(NotificationEngineService::class)->send($user, new SendNotificationDTO(
                title: $title,
                message: $message,
                category: $category,
                priority: $priority,
                actions: $actions,
                metadata: $metadata,
            ));
        } catch (Throwable $e) {
            // Safe fallback so a notification failure never blocks the caller
        }
    }

    /**
     * Reschedule an existing Demo.
     */
    public function rescheduleDemo(Demo $demo, array $data, ?User $user = null): Demo
    {
        $oldScheduledAt = $demo->scheduled_at;
        $newScheduledAt = Carbon::parse($data['scheduled_at']);
        $duration = (int) ($data['duration_minutes'] ?? $demo->duration_minutes);
        $notes = $data['notes'] ?? $demo->notes;
        $meetingLink = $data['meeting_link'] ?? $demo->meeting_link;

        // The demo being moved is excluded from the clash check — it is about
        // to be superseded, so it cannot conflict with its own replacement.
        $this->assertPresenterAvailable(
            $data['presenter_id'] ?? $demo->presenter_id,
            $newScheduledAt,
            $duration,
            $demo->id
        );

        // Preserve history by updating status of current demo to Rescheduled
        $demo->update([
            'status' => DemoStatus::Rescheduled,
        ]);

        // Create new linked Demo record
        $newDemo = Demo::create([
            'lead_id' => $demo->lead_id,
            'presenter_id' => $data['presenter_id'] ?? $demo->presenter_id,
            'scheduled_at' => $newScheduledAt,
            'duration_minutes' => $duration,
            'demo_mode' => $data['demo_mode'] ?? $demo->demo_mode ?? 'online',
            'location' => $data['location'] ?? $demo->location,
            'status' => DemoStatus::Scheduled,
            'confirmation_status' => DemoConfirmationStatus::Pending,
            'meeting_link' => $meetingLink,
            'notes' => $notes,
            'rescheduled_from_id' => $demo->id,
        ]);

        // Log reschedule activity
        $demo->lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Demo,
            'description' => "Demo rescheduled from {$oldScheduledAt->format('M d, Y h:i A')} to {$newScheduledAt->format('M d, Y h:i A')}.",
            'properties' => [
                'old_demo_id' => $demo->id,
                'new_demo_id' => $newDemo->id,
                'old_scheduled_at' => $oldScheduledAt->toDateTimeString(),
                'new_scheduled_at' => $newScheduledAt->toDateTimeString(),
            ],
        ]);

        // Client reminder
        $this->sendClientDemoReminder($newDemo);

        // Employee notification
        if ($newDemo->presenter_id) {
            $this->notifyUser(
                $newDemo->presenter_id,
                'Demo Rescheduled',
                "Demo for {$demo->lead->company_name} rescheduled to {$newScheduledAt->format('M d, Y h:i A')}.",
                NotificationPriority::HIGH,
                ['type' => 'demo_rescheduled', 'demo_id' => $newDemo->id, 'lead_id' => $demo->lead_id]
            );
        }

        return $newDemo;
    }

    /**
     * Update client confirmation status of a Demo.
     */
    /**
     * A demo already at a terminal state cannot move to a different one.
     * Shared by every demo transition — `cancelDemo()` included — so a
     * completed, no-show'd, cancelled or superseded (rescheduled) demo stays
     * that way regardless of which endpoint is asked to change it.
     *
     * @throws InvalidArgumentException
     */
    protected function assertDemoTransitionAllowed(Demo $demo, string $verb): void
    {
        $terminal = [DemoStatus::Completed, DemoStatus::NoShow, DemoStatus::Cancelled, DemoStatus::Rescheduled];

        if (in_array($demo->status, $terminal, true)) {
            throw new InvalidArgumentException("This demo is already {$demo->status->label()} and cannot be {$verb}.");
        }
    }

    public function confirmDemo(Demo $demo, string $confirmationStatus, ?User $user = null): Demo
    {
        $this->assertDemoTransitionAllowed($demo, 'confirmed');

        $statusEnum = DemoConfirmationStatus::tryFrom($confirmationStatus) ?? DemoConfirmationStatus::Confirmed;

        $demo->update([
            'confirmation_status' => $statusEnum,
            'status' => $statusEnum === DemoConfirmationStatus::Confirmed ? DemoStatus::Confirmed : $demo->status,
        ]);

        $demo->lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Demo,
            'description' => "Demo confirmation status updated to: {$statusEnum->label()}",
            'properties' => [
                'demo_id' => $demo->id,
                'confirmation_status' => $statusEnum->value,
            ],
        ]);

        return $demo->fresh();
    }

    /**
     * Complete a Demo and record feedback, outcome, and stage transitions.
     */
    public function completeDemo(Demo $demo, array $data, ?User $user = null): Demo
    {
        $this->assertDemoTransitionAllowed($demo, 'completed');

        $rawOutcome = $data['outcome'] ?? DemoOutcome::Interested->value;
        $outcomeEnum = DemoOutcome::tryFrom($rawOutcome) ?? DemoOutcome::Interested;
        $feedback = $data['feedback'] ?? null;
        $objections = $data['objections'] ?? null;
        $nextAction = $data['next_action'] ?? null;
        $nextFollowupAt = $data['next_followup_at'] ?? null;

        $demo->update([
            'status' => DemoStatus::Completed,
            'outcome' => $outcomeEnum,
            'feedback' => $feedback,
            'objections' => $objections,
            'next_action' => $nextAction,
        ]);

        $lead = $demo->lead;

        // Auto transition Lead stage based on demo outcome
        $targetStage = LeadStatus::DemoCompleted->value;
        if ($outcomeEnum === DemoOutcome::TrialRequested) {
            if (! Trial::where('lead_id', $lead->id)->exists()) {
                $this->createTrial($lead, ['duration_days' => 14, 'status' => TrialStatus::Active->value], $user);
            }
            $targetStage = LeadStatus::TrialActive->value;
        } elseif ($outcomeEnum === DemoOutcome::QuotationRequested) {
            $targetStage = LeadStatus::Negotiation->value;
        } elseif ($outcomeEnum === DemoOutcome::Lost) {
            $targetStage = LeadStatus::Lost->value;
        }

        /*
         * A closed deal is not reopened by an activity finishing on it.
         *
         * Completing a demo drove the stage from its outcome unconditionally,
         * so a post-sale walkthrough on a Won deal pulled it back to Demo
         * Completed — while its customer organisation stayed provisioned. The
         * deal then read as mid-pipeline and as a live customer at once.
         */
        if (! in_array($this->stageValue($lead), [LeadStatus::Won->value, LeadStatus::Lost->value], true)) {
            $this->transitionStage($lead, $targetStage, $user, "Demo completed - Outcome: {$outcomeEnum->label()}");
        }

        // Schedule next follow-up task if provided
        if ($nextFollowupAt) {
            Task::create([
                'title' => 'Post-Demo Follow up',
                'description' => $nextAction ?? "Follow up on demo outcome: {$outcomeEnum->label()}",
                'type' => TaskType::FollowUp,
                'status' => TaskStatus::Pending,
                'priority' => TaskPriority::High,
                'due_at' => Carbon::parse($nextFollowupAt),
                'assigned_user_id' => $demo->presenter_id ?? $lead->assigned_user_id,
                'creator_id' => $user?->id,
                'taskable_type' => $lead->getMorphClass(),
                'taskable_id' => $lead->id,
            ]);
        }

        // Log canonical Activity with feedback
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Demo,
            'description' => 'Demo completed.'.($feedback ? "\nFeedback: {$feedback}" : '').($objections ? "\nObjections: {$objections}" : ''),
            'properties' => [
                'demo_id' => $demo->id,
                'outcome' => $outcomeEnum->value,
                'feedback' => $feedback,
                'objections' => $objections,
                'next_action' => $nextAction,
            ],
        ]);

        // Ask the client for their own feedback/rating on the demo
        $this->sendDemoFeedbackRequest($demo);

        return $demo->fresh();
    }

    /**
     * Mark Demo as No Show.
     */
    public function markDemoNoShow(Demo $demo, array $data = [], ?User $user = null): Demo
    {
        $this->assertDemoTransitionAllowed($demo, 'marked as no-show');

        $notes = $data['notes'] ?? 'Client did not attend scheduled demo.';

        $demo->update([
            'status' => DemoStatus::NoShow,
            'notes' => $demo->notes ? $demo->notes."\n[No-Show]: ".$notes : $notes,
        ]);

        $lead = $demo->lead;

        // Log No-Show Activity
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Demo,
            'description' => "Client marked as No-Show for demo scheduled at {$demo->scheduled_at->format('M d, Y h:i A')}. Notes: {$notes}",
            'properties' => [
                'demo_id' => $demo->id,
                'scheduled_at' => $demo->scheduled_at->toDateTimeString(),
            ],
        ]);

        // Create follow-up task to reschedule
        Task::create([
            'title' => "No-Show Reschedule: {$lead->company_name}",
            'description' => 'Client missed demo. Contact client to reschedule demo appointment.',
            'type' => TaskType::Call,
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::High,
            'due_at' => now()->addHours(4),
            'assigned_user_id' => $demo->presenter_id ?? $lead->assigned_user_id,
            'creator_id' => $user?->id,
            'taskable_type' => $lead->getMorphClass(),
            'taskable_id' => $lead->id,
        ]);

        return $demo->fresh();
    }

    /**
     * Cancel a Demo.
     *
     * A proper lifecycle transition, not a deletion: the record, its history
     * and its activity trail all survive so a cancelled demo stays queryable
     * and auditable. A reason is mandatory — cancelling a scheduled
     * commitment without saying why is exactly the silent-drift Phase 3
     * closes.
     *
     * The pending "Demo Scheduled" reminder task this demo's own scheduling
     * created is cancelled alongside it, matched on the lead, the Demo task
     * type and the exact `due_at` this demo stamped it with at creation —
     * precise enough to leave every other task on the lead (an unrelated
     * follow-up, another demo's own reminder) untouched.
     */
    public function cancelDemo(Demo $demo, string $reason, ?User $user = null): Demo
    {
        $this->assertDemoTransitionAllowed($demo, 'cancelled');

        $demo->update([
            'status' => DemoStatus::Cancelled,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'cancelled_by_id' => $user?->id,
        ]);

        $lead = $demo->lead;

        Task::where('taskable_id', $lead->id)
            ->where('taskable_type', $lead->getMorphClass())
            ->where('type', TaskType::Demo->value)
            ->where('status', TaskStatus::Pending->value)
            ->where('due_at', $demo->scheduled_at)
            ->update(['status' => TaskStatus::Cancelled->value]);

        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Demo,
            'description' => "Demo scheduled for {$demo->scheduled_at->format('M d, Y h:i A')} was cancelled. Reason: {$reason}",
            'properties' => [
                'demo_id' => $demo->id,
                'scheduled_at' => $demo->scheduled_at->toDateTimeString(),
                'cancellation_reason' => $reason,
            ],
        ]);

        return $demo->fresh();
    }

    /**
     * Build and return Demo Workspace Command Center payload.
     */
    public function getDemoWorkspacePayload(?User $user = null, array $filters = []): array
    {
        // Visibility follows CrmScope, which derives it from permissions:
        // a manager sees their tenant's demos, a rep sees demos on the leads
        // assigned to them, a master admin sees everything.
        //
        // This used to narrow on `$user->is_admin` — a column that does not
        // exist on `users`, so the check was always falsy and every company
        // user, managers included, saw only demos they had personally
        // presented.
        $query = CrmScope::demos($user)
            ->with(['lead:id,company_name,contact_name,phone,email,status,organization_id', 'presenter:id,name,email']);

        $allDemos = $query->orderBy('scheduled_at', 'desc')->get();

        $startOfDay = now()->startOfDay();
        $endOfDay = now()->endOfDay();
        $sevenDays = now()->addDays(7)->endOfDay();

        $todaysDemos = $allDemos->filter(fn ($d) => $d->scheduled_at >= $startOfDay && $d->scheduled_at <= $endOfDay && $d->status !== DemoStatus::Cancelled);
        $upcomingDemos = $allDemos->filter(fn ($d) => $d->scheduled_at > $endOfDay && $d->scheduled_at <= $sevenDays && in_array($d->status, [DemoStatus::Scheduled, DemoStatus::Confirmed]));
        $needsAttention = $allDemos->filter(function ($d) {
            return $d->status === DemoStatus::NoShow
                || ($d->status === DemoStatus::Completed && empty($d->feedback))
                || ($d->scheduled_at < now() && in_array($d->status, [DemoStatus::Scheduled, DemoStatus::Confirmed]))
                || ($d->confirmation_status === DemoConfirmationStatus::Pending && $d->scheduled_at <= now()->addHours(24) && $d->scheduled_at >= now());
        });

        $metrics = [
            'todaysCount' => $todaysDemos->count(),
            'upcomingCount' => $upcomingDemos->count(),
            'completedCount' => $allDemos->where('status', DemoStatus::Completed)->count(),
            'noShowCount' => $allDemos->where('status', DemoStatus::NoShow)->count(),
            'rescheduledCount' => $allDemos->where('status', DemoStatus::Rescheduled)->count(),
            'pendingConfirmationCount' => $allDemos->where('confirmation_status', DemoConfirmationStatus::Pending)->count(),
            'feedbackPendingCount' => $allDemos->filter(fn ($d) => $d->status === DemoStatus::Completed && empty($d->feedback))->count(),
        ];

        return [
            'metrics' => $metrics,
            'todaysDemos' => $todaysDemos->values(),
            'upcomingDemos' => $upcomingDemos->values(),
            'needsAttention' => $needsAttention->values(),
            'allDemos' => $allDemos->values(),
        ];
    }

    /**
     * Check and generate employee & client reminders for approaching and overdue demos.
     */
    public function checkAndGenerateDemoReminders(?int $organizationId = null): array
    {
        $query = Demo::with(['lead', 'presenter'])
            ->whereIn('status', [DemoStatus::Scheduled, DemoStatus::Confirmed])
            ->whereHas('lead', function ($q) use ($organizationId) {
                $q->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value]);
                if ($organizationId) {
                    $q->where('organization_id', $organizationId);
                }
            });

        $demos = $query->get();
        $notificationsSent = 0;

        foreach ($demos as $demo) {
            $type = $demo->scheduled_at < now()->subMinutes(15)
                ? 'demo_overdue'
                : ($demo->scheduled_at >= now() && $demo->scheduled_at <= now()->addHour() ? 'demo_approaching' : null);

            if (! $type || ! $demo->presenter_id) {
                continue;
            }

            $exists = EnterpriseNotification::where('notifiable_id', $demo->presenter_id)
                ->where('notifiable_type', User::class)
                ->where('data->metadata->demo_id', $demo->id)
                ->where('data->metadata->type', $type)
                ->exists();

            if ($exists) {
                continue;
            }

            $this->notifyUser(
                $demo->presenter_id,
                $type === 'demo_overdue' ? 'Demo Overdue' : 'Demo Approaching',
                $type === 'demo_overdue'
                    ? "Demo for {$demo->lead->company_name} scheduled at {$demo->scheduled_at->format('H:i')} is past due. Mark complete or reschedule."
                    : "Demo for {$demo->lead->company_name} starts in less than 1 hour ({$demo->scheduled_at->format('H:i')}).",
                NotificationPriority::HIGH,
                ['type' => $type, 'demo_id' => $demo->id]
            );
            $notificationsSent++;
        }

        return ['notifications_sent' => $notificationsSent];
    }

    /**
     * Create a new Trial for a Lead.
     */
    public function createTrial(Lead $lead, array $data, ?User $user = null): Trial
    {
        // Enforce no active trial exists
        $existingActive = Trial::where('lead_id', $lead->id)
            ->whereIn('status', array_merge([TrialStatus::Preparing], TrialStatus::live()))
            ->first();

        if ($existingActive) {
            return $existingActive;
        }

        $companyPlan = ! empty($data['company_plan_id']) ? CompanyPlan::find($data['company_plan_id']) : null;

        /*
         * Sales offers 7 or 14 days; the plan may override.
         *
         * The fallback here used to be a bare 15 — a number that matched
         * neither of the lengths actually offered, so a trial created without
         * an explicit duration ran a day longer than the longest option on the
         * form. Config now names the lengths and the default in one place.
         */
        $duration = (int) ($data['duration_days'] ?? $companyPlan?->trial_duration_days ?? config('crm.trial_default_days', 14));
        $duration = max(1, min((int) config('crm.trial_max_days', 90), $duration));

        $startDate = isset($data['start_date']) ? Carbon::parse($data['start_date']) : Carbon::today();
        $endDate = isset($data['end_date']) ? Carbon::parse($data['end_date']) : $startDate->copy()->addDays($duration);
        $plan = $data['plan'] ?? $companyPlan?->name ?? 'Basic Trial Plan';
        $maxDoctors = $data['max_doctors'] ?? $companyPlan?->max_doctors;
        $assignedUserId = $data['assigned_user_id'] ?? $lead->assigned_user_id ?? $user?->id;

        $defaultChecklist = [
            ['id' => '1', 'task' => 'Confirm client details', 'completed' => false],
            ['id' => '2', 'task' => 'Confirm primary contact', 'completed' => false],
            ['id' => '3', 'task' => 'Confirm clinic name', 'completed' => false],
            ['id' => '4', 'task' => 'Confirm trial plan', 'completed' => false],
            ['id' => '5', 'task' => 'Confirm trial start date', 'completed' => false],
            ['id' => '6', 'task' => 'Confirm trial end date', 'completed' => false],
            ['id' => '7', 'task' => 'Create trial environment', 'completed' => false],
            ['id' => '8', 'task' => 'Configure basic clinic information', 'completed' => false],
            ['id' => '9', 'task' => 'Create trial user/access', 'completed' => false],
            ['id' => '10', 'task' => 'Send access information', 'completed' => false],
            ['id' => '11', 'task' => 'Schedule trial orientation', 'completed' => false],
            ['id' => '12', 'task' => 'Confirm client received access', 'completed' => false],
        ];

        $status = isset($data['status']) ? (is_string($data['status']) ? (TrialStatus::tryFrom($data['status']) ?? TrialStatus::Preparing) : $data['status']) : TrialStatus::Preparing;
        $expectedUsers = (int) ($data['expected_users'] ?? $lead->expected_users ?? 1);

        $trial = Trial::create([
            'lead_id' => $lead->id,
            'company_plan_id' => $companyPlan?->id,
            'plan' => $plan,
            'duration_days' => $duration,
            'expected_users' => $expectedUsers,
            'max_doctors' => $maxDoctors,
            'assigned_user_id' => $assignedUserId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'original_end_date' => $endDate,
            'status' => $status,
            'notes' => $data['notes'] ?? null,
            'onboarding_checklist' => $data['onboarding_checklist'] ?? $defaultChecklist,
        ]);

        // Employee notification
        if ($assignedUserId) {
            $this->notifyUser(
                $assignedUserId,
                'New Trial Created',
                "Trial created for {$lead->company_name} ({$duration} Days, {$plan}).",
                NotificationPriority::HIGH,
                ['type' => 'trial_created', 'trial_id' => $trial->id, 'lead_id' => $lead->id]
            );
        }

        // Send client WhatsApp/email notification
        $this->sendClientTrialReminder($trial, 'created');

        // Log canonical Activity
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Trial,
            'description' => "Trial setup created ({$duration} Days - {$plan}). Start: {$startDate->format('M d, Y')}, End: {$endDate->format('M d, Y')}.",
            'properties' => [
                'trial_id' => $trial->id,
                'plan' => $plan,
                'duration_days' => $duration,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
        ]);

        return $trial;
    }

    /**
     * Activate a Trial and transition Lead stage to TrialActive.
     */
    public function activateTrial(Trial $trial, ?User $user = null): Trial
    {
        $trial->update([
            'status' => TrialStatus::Active,
        ]);

        $lead = $trial->lead;

        /*
         * Closed-deal guard, now the general rule rather than a local one.
         *
         * Re-provisioning a won customer is a legitimate repair action and must
         * not drag the deal back into TrialActive. That used to be a hand-rolled
         * check here; `transitionStage()` refuses the move for every caller now,
         * so this only has to decide whether to ask.
         */
        if (! in_array($this->stageValue($lead), DealStageService::TERMINAL, true)) {
            $this->transitionStage($lead, LeadStatus::TrialActive->value, $user, "Trial activated - {$trial->duration_days} Days ({$trial->plan})");
        }

        // Send client reminder
        $this->sendClientTrialReminder($trial, 'started');

        // Employee task
        Task::create([
            'title' => "Trial Mid-Point Check: {$lead->company_name}",
            'description' => 'Contact client for trial usage check and evaluation feedback.',
            'type' => TaskType::FollowUp,
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::High,
            'due_at' => $trial->start_date->copy()->addDays((int) floor($trial->duration_days / 2)),
            'assigned_user_id' => $trial->assigned_user_id ?? $lead->assigned_user_id,
            'creator_id' => $user?->id,
            'taskable_type' => $lead->getMorphClass(),
            'taskable_id' => $lead->id,
        ]);

        // Log canonical Activity
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Trial,
            'description' => "Trial activated and live! Valid from {$trial->start_date->format('M d')} to {$trial->end_date->format('M d, Y')}.",
            'properties' => [
                'trial_id' => $trial->id,
                'status' => TrialStatus::Active->value,
            ],
        ]);

        return $trial->fresh();
    }

    /**
     * Update onboarding checklist for a Trial.
     */
    public function updateTrialChecklist(Trial $trial, array $checklist, ?User $user = null): Trial
    {
        $trial->update([
            'onboarding_checklist' => $checklist,
        ]);

        $completedCount = collect($checklist)->where('completed', true)->count();
        $totalCount = count($checklist);

        $trial->lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Trial,
            'description' => "Trial onboarding checklist updated ({$completedCount}/{$totalCount} items completed).",
            'properties' => [
                'trial_id' => $trial->id,
                'completed_count' => $completedCount,
                'total_count' => $totalCount,
            ],
        ]);

        return $trial->fresh();
    }

    /**
     * Extend an active Trial (Governed: max 1 extension).
     *
     * @deprecated Moves `trials.end_date` only. Use
     * {@see TrialLifecycleService::extend()}, which moves the
     * subscription window with it — otherwise the customer is promised another
     * week and locked out on the original date. Kept because existing tests
     * cover this method's governance cap; the controller no longer calls it.
     */
    /**
     * Maximum number of times a single trial can be extended — a soft
     * governance cap, not a hard one-time-only rule, so admins can grant a
     * genuinely interested prospect more runway without editing the DB.
     */
    protected const MAX_TRIAL_EXTENSIONS = 3;

    public function extendTrial(Trial $trial, array $data, ?User $user = null): Trial
    {
        if ($trial->extension_count >= self::MAX_TRIAL_EXTENSIONS) {
            throw new InvalidArgumentException('This trial has already been extended the maximum of '.self::MAX_TRIAL_EXTENSIONS.' times.');
        }

        $additionalDays = max(1, min(60, (int) ($data['additional_days'] ?? 7)));
        $reason = $data['extension_reason'] ?? $data['reason'] ?? 'Client requested additional evaluation time.';
        $oldEndDate = $trial->end_date;
        $newEndDate = $oldEndDate->copy()->addDays($additionalDays);

        $trial->update([
            'original_end_date' => $trial->original_end_date ?? $oldEndDate,
            'end_date' => $newEndDate,
            'duration_days' => $trial->duration_days + $additionalDays,
            'extension_count' => $trial->extension_count + 1,
            'extended_at' => now(),
            'extension_reason' => $reason,
            'status' => TrialStatus::Extended,
        ]);

        $lead = $trial->lead;

        // Log canonical Activity with original & new end dates
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Trial,
            'description' => "Trial extended by {$additionalDays} days (from {$oldEndDate->format('M d')} to {$newEndDate->format('M d, Y')}). Reason: {$reason}",
            'properties' => [
                'trial_id' => $trial->id,
                'old_end_date' => $oldEndDate->toDateString(),
                'new_end_date' => $newEndDate->toDateString(),
                'additional_days' => $additionalDays,
                'extension_reason' => $reason,
            ],
        ]);

        // Send client reminder
        $this->sendClientTrialReminder($trial, 'extended');

        return $trial->fresh();
    }

    /**
     * Complete a Trial and record outcome, feedback, and next steps.
     */
    public function completeTrial(Trial $trial, array $data, ?User $user = null): Trial
    {
        /*
         * `TrialLifecycleService::cancel()`/`extend()` both refuse a
         * terminal trial; this, their sibling transition, did not. A trial
         * already cancelled (for a mistaken account, a duplicate, a customer
         * pulling out) could still be completed here — flipping it to
         * `Completed`, stamping `completed_at`, and re-running the lead-stage
         * side effects below on a deal that had already been closed out the
         * other way.
         */
        if (in_array($trial->status, TrialLifecycleService::TERMINAL, true)) {
            throw new InvalidArgumentException("This trial is already {$trial->status->label()} and cannot be completed.");
        }

        $rawOutcome = $data['outcome'] ?? TrialOutcome::Interested->value;
        $outcomeEnum = TrialOutcome::tryFrom($rawOutcome) ?? TrialOutcome::Interested;
        $feedback = $data['feedback'] ?? null;
        $objections = $data['objections'] ?? null;
        $requestedFeatures = $data['requested_features'] ?? null;
        $nextAction = $data['next_action'] ?? null;
        $nextFollowupAt = $data['next_followup_at'] ?? null;

        $targetTrialStatus = $outcomeEnum === TrialOutcome::QuotationRequested
            ? TrialStatus::QuotationRequested
            : TrialStatus::Completed;

        $trial->update([
            'status' => $targetTrialStatus,
            'outcome' => $outcomeEnum,
            'feedback' => $feedback,
            'objections' => $objections,
            'requested_features' => $requestedFeatures,
            'next_action' => $nextAction,
            'next_followup_at' => $nextFollowupAt ? Carbon::parse($nextFollowupAt) : null,
            'completed_at' => now(),
        ]);

        $lead = $trial->lead;

        // Auto transition Lead stage based on trial outcome
        $targetStage = LeadStatus::DemoCompleted->value; // fallback
        if ($outcomeEnum === TrialOutcome::QuotationRequested || $outcomeEnum === TrialOutcome::HighlyInterested) {
            $targetStage = LeadStatus::Negotiation->value;
        } elseif ($outcomeEnum === TrialOutcome::Lost || $outcomeEnum === TrialOutcome::NotInterested) {
            $targetStage = LeadStatus::Lost->value;
        } else {
            $targetStage = LeadStatus::TrialActive->value;
        }

        /*
         * The fallback above is TrialActive, so completing a trial on a won
         * deal would have moved that deal *backwards* out of Won. Recording
         * the outcome is still correct; moving the stage is not.
         */
        if (! in_array($this->stageValue($lead), [LeadStatus::Won->value, LeadStatus::Lost->value], true)) {
            $this->transitionStage($lead, $targetStage, $user, "Trial completed - Outcome: {$outcomeEnum->label()}");
        }

        // Schedule next follow-up task
        if ($nextFollowupAt) {
            Task::create([
                'title' => "Post-Trial Next Step: {$lead->company_name}",
                'description' => $nextAction ?? "Follow up on trial outcome: {$outcomeEnum->label()}",
                'type' => TaskType::FollowUp,
                'status' => TaskStatus::Pending,
                'priority' => TaskPriority::High,
                'due_at' => Carbon::parse($nextFollowupAt),
                'assigned_user_id' => $trial->assigned_user_id ?? $lead->assigned_user_id,
                'creator_id' => $user?->id,
                'taskable_type' => $lead->getMorphClass(),
                'taskable_id' => $lead->id,
            ]);
        }

        // Log canonical Activity
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Trial,
            'description' => "Trial completed - Outcome: {$outcomeEnum->label()}.".($feedback ? "\nFeedback: {$feedback}" : ''),
            'properties' => [
                'trial_id' => $trial->id,
                'outcome' => $outcomeEnum->value,
                'feedback' => $feedback,
                'objections' => $objections,
                'requested_features' => $requestedFeatures,
                'next_action' => $nextAction,
            ],
        ]);

        return $trial->fresh();
    }

    /**
     * Send client WhatsApp/Email trial reminder respecting Marketing M-8/M-9 governance rules.
     */
    public function sendClientTrialReminder(Trial $trial, string $reminderType = 'started'): bool
    {
        $lead = $trial->lead;
        if (! $lead) {
            return false;
        }

        // Halts automation if Lead is Won or Lost
        $statusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
        if ($statusVal === LeadStatus::Won->value || $statusVal === LeadStatus::Lost->value) {
            return false;
        }

        $orgId = $lead->organization_id;

        // Governance Check 1: Marketing Contact Opt-Out & Suppression (M-8/M-9)
        $contact = $lead->marketingContact ?? MarketingContact::where('organization_id', $orgId)
            ->where('normalized_phone', $lead->normalized_phone)
            ->first();

        if ($contact && in_array($contact->marketing_status, ['opted_out', 'suppressed'])) {
            return false;
        }

        // Governance Check 2: Emergency Freeze Kill-Switch & Quiet Hours
        $settingsService = app(MarketingSettingsService::class);
        if ($settingsService->isEmergencyFreezeActive($orgId)) {
            return false;
        }

        if ($settingsService->isInQuietHours($orgId)) {
            return false;
        }

        if (! $lead->phone) {
            return false;
        }

        $startStr = $trial->start_date ? $trial->start_date->format('M d, Y') : now()->format('M d, Y');
        $endStr = $trial->end_date ? $trial->end_date->format('M d, Y') : now()->addDays(14)->format('M d, Y');

        $messageText = match ($reminderType) {
            'created' => "Hello {$lead->contact_name}, your OralCares {$trial->plan} ({$trial->duration_days} Days) trial has been scheduled from {$startStr} to {$endStr}.",
            'ending_tomorrow' => "Hello {$lead->contact_name}, your OralCares trial ends tomorrow ({$endStr}). Reply to discuss plan selection or questions.",
            'expired' => "Hello {$lead->contact_name}, your OralCares trial completed on {$endStr}. We'd love to assist with your custom plan quotation.",
            'extended' => "Hello {$lead->contact_name}, your OralCares trial has been extended to {$endStr}. Enjoy evaluating our features!",
            /*
             * Grace is full access, and saying so plainly is the point — a
             * customer who thinks they have been cut off stops using the
             * product on the very days we most want them using it.
             */
            'ending_soon' => "Hello {$lead->contact_name}, your OralCares trial ends on {$endStr}. Shall we set up a call to talk through plans?",
            'grace_started' => "Hello {$lead->contact_name}, your OralCares trial period ended on {$endStr}, and you still have full access for 7 more days. Reply any time and we'll help you choose a plan.",
            'grace_ending' => "Hello {$lead->contact_name}, your OralCares access ends tomorrow. Reply today and we'll keep your clinic set up exactly as you have it.",
            'lapsed' => "Hello {$lead->contact_name}, your OralCares trial access has now ended. Your clinic setup is safe — reply and we can restore access straight away.",
            default => "Hello {$lead->contact_name}, your OralCares {$trial->plan} is now active until {$endStr}. Access details have been configured.",
        };

        $templateName = "trial_{$reminderType}";
        $result = $this->sendCrmWhatsAppTemplate(
            $orgId,
            $templateName,
            $lead->phone,
            [$lead->contact_name, $endStr],
            "trial:{$trial->id}:{$reminderType}"
        );

        $lead->activities()->create([
            'user_id' => null,
            'type' => ActivityType::WhatsApp,
            'description' => $result['success']
                ? "Automated client trial reminder sent ({$reminderType}): {$endStr}"
                : "Trial reminder ({$reminderType}) NOT sent ({$this->describeCrmSendFailure($result)}) — logged only.",
            'properties' => [
                'trial_id' => $trial->id,
                'reminder_type' => $reminderType,
            ],
        ]);

        return $result['success'];
    }

    /**
     * Build and return Trial Workspace Command Center payload.
     */
    public function getTrialWorkspacePayload(?User $user = null, array $filters = []): array
    {
        // Same correction as demos: `$user->is_admin` is not a column on
        // `users`, so this narrowed every company user to trials they were
        // personally assigned. CrmScope::trials() already honours both the
        // trial's own owner and the parent lead's.
        $query = CrmScope::trials($user)
            ->with(['lead:id,company_name,contact_name,phone,email,status,organization_id', 'assignedUser:id,name,email']);

        $allTrials = $query->orderBy('end_date', 'asc')->get();

        $startOfDay = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        $activeTrials = $allTrials->filter(fn ($t) => $t->status instanceof TrialStatus && $t->status->grantsAccess());
        $startingToday = $allTrials->filter(fn ($t) => $t->start_date && $t->start_date->isToday());
        $endingToday = $allTrials->filter(fn ($t) => $t->end_date && $t->end_date->isToday());
        $endingSoon = $allTrials->filter(fn ($t) => $t->end_date && $t->end_date > $endOfDay && $t->end_date <= now()->addDays(3)->endOfDay() && in_array($t->status, [TrialStatus::Active, TrialStatus::Extended]));
        $needsAttention = $allTrials->filter(function ($t) {
            return $t->status === TrialStatus::Expired
                || ($t->end_date && $t->end_date <= now()->addDay() && in_array($t->status, [TrialStatus::Active, TrialStatus::Extended]))
                || ($t->status === TrialStatus::Completed && empty($t->feedback));
        });

        $metrics = [
            'activeCount' => $activeTrials->count(),
            'startingTodayCount' => $startingToday->count(),
            'endingTodayCount' => $endingToday->count(),
            'endingSoonCount' => $endingSoon->count(),
            'needsAttentionCount' => $needsAttention->count(),
            'completedCount' => $allTrials->where('status', TrialStatus::Completed)->count(),
            'quotationRequestedCount' => $allTrials->where('status', TrialStatus::QuotationRequested)->count(),
            'expiredCount' => $allTrials->where('status', TrialStatus::Expired)->count(),
        ];

        return [
            'metrics' => $metrics,
            'activeTrials' => $activeTrials->values(),
            'startingToday' => $startingToday->values(),
            'endingSoon' => $endingSoon->values(),
            'needsAttention' => $needsAttention->values(),
            'allTrials' => $allTrials->values(),
        ];
    }

    /**
     * Check and generate reminders for trials ending tomorrow and expired trials.
     *
     * @deprecated Superseded by {@see TrialLifecycleService::syncAll()}.
     * This asks `isTomorrow()` and `isPast()`, which are true on one calendar day
     * each — so a scheduler run that did not happen skipped that milestone
     * permanently, and one that ran twice sent the client two messages. It also
     * expires a trial the day after its end date, which contradicts the seven-day
     * grace period. Nothing calls it any more; kept only because one existing
     * test still covers its behaviour.
     */
    public function checkAndGenerateTrialReminders(?int $organizationId = null): array
    {
        $query = Trial::with(['lead', 'assignedUser'])
            ->whereIn('status', [TrialStatus::Active, TrialStatus::Extended, TrialStatus::EndingSoon])
            ->whereHas('lead', function ($q) use ($organizationId) {
                $q->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value]);
                if ($organizationId) {
                    $q->where('organization_id', $organizationId);
                }
            });

        $trials = $query->get();
        $notificationsSent = 0;

        foreach ($trials as $trial) {
            // Ending tomorrow (or today)
            if ($trial->end_date && $trial->end_date->isTomorrow()) {
                if ($trial->assigned_user_id) {
                    $exists = EnterpriseNotification::where('notifiable_id', $trial->assigned_user_id)
                        ->where('notifiable_type', User::class)
                        ->where('data->metadata->trial_id', $trial->id)
                        ->where('data->metadata->type', 'trial_ending_tomorrow')
                        ->exists();

                    if (! $exists) {
                        $this->notifyUser(
                            $trial->assigned_user_id,
                            'Trial Ending Tomorrow',
                            "Trial for {$trial->lead->company_name} ends tomorrow ({$trial->end_date->format('M d, Y')}). Contact client.",
                            NotificationPriority::HIGH,
                            ['type' => 'trial_ending_tomorrow', 'trial_id' => $trial->id, 'lead_id' => $trial->lead_id]
                        );
                        $notificationsSent++;
                    }
                }

                // Send client WhatsApp reminder
                $this->sendClientTrialReminder($trial, 'ending_tomorrow');
            }
            // Expired (end date is in past)
            elseif ($trial->end_date && $trial->end_date->isPast() && ! $trial->end_date->isToday()) {
                $trial->update(['status' => TrialStatus::Expired]);

                if ($trial->assigned_user_id) {
                    $exists = EnterpriseNotification::where('notifiable_id', $trial->assigned_user_id)
                        ->where('notifiable_type', User::class)
                        ->where('data->metadata->trial_id', $trial->id)
                        ->where('data->metadata->type', 'trial_expired')
                        ->exists();

                    if (! $exists) {
                        $this->notifyUser(
                            $trial->assigned_user_id,
                            'Trial Expired',
                            "Trial for {$trial->lead->company_name} has expired. Follow up to confirm next steps.",
                            NotificationPriority::HIGH,
                            ['type' => 'trial_expired', 'trial_id' => $trial->id, 'lead_id' => $trial->lead_id]
                        );
                        $notificationsSent++;
                    }
                }

                // Create follow-up task
                Task::create([
                    'title' => "Expired Trial Follow-up: {$trial->lead->company_name}",
                    'description' => "Trial expired on {$trial->end_date->format('M d, Y')}. Contact client for feedback or quotation request.",
                    'type' => TaskType::Call,
                    'status' => TaskStatus::Pending,
                    'priority' => TaskPriority::High,
                    'due_at' => now()->addHours(4),
                    'assigned_user_id' => $trial->assigned_user_id ?? $trial->lead->assigned_user_id,
                    'creator_id' => null,
                    'taskable_type' => $trial->lead->getMorphClass(),
                    'taskable_id' => $trial->lead->id,
                ]);
            }
        }

        return ['notifications_sent' => $notificationsSent];
    }

    /**
     * Expand a selected package, its additional users, and its add-ons into
     * concrete line-item rows.
     *
     * Each returned row snapshots its own title and price at this exact
     * moment — nothing downstream ever re-reads `CompanyPlan`/
     * `CompanyPackageAddon` to price an existing quotation, so a later price
     * change on the catalogue never rewrites history. `item_type` is
     * classification metadata only, carried through by
     * `saveItemsAndCalculateTotals()`.
     *
     * An unknown or inactive plan/add-on id is silently skipped rather than
     * failing the whole quotation — the same tolerance a freeform custom
     * line already has for a blank title.
     *
     * @param  list<int>  $addonIds
     * @return list<array<string, mixed>>
     */
    protected function buildPackageLineItems(?int $companyPlanId, int $additionalUsers, array $addonIds): array
    {
        $lines = [];

        $plan = $companyPlanId ? CompanyPlan::where('is_active', true)->find($companyPlanId) : null;

        if ($plan) {
            $lines[] = [
                'title' => $plan->name,
                'description' => $plan->description,
                'item_type' => 'package_base',
                'quantity' => 1,
                'unit_price' => (float) $plan->price,
                'discount' => 0.00,
            ];

            if ($additionalUsers > 0 && $plan->additional_user_price !== null) {
                $lines[] = [
                    'title' => "Additional users (+{$additionalUsers})",
                    'description' => "Beyond the {$plan->max_users} users included in {$plan->name}.",
                    'item_type' => 'additional_users',
                    'quantity' => $additionalUsers,
                    'unit_price' => (float) $plan->additional_user_price,
                    'discount' => 0.00,
                ];
            }
        }

        if (! empty($addonIds)) {
            CompanyPackageAddon::whereIn('id', $addonIds)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->each(function (CompanyPackageAddon $addon) use (&$lines) {
                    $lines[] = [
                        'title' => $addon->name,
                        'description' => null,
                        'item_type' => 'addon',
                        'company_package_addon_id' => $addon->id,
                        'quantity' => 1,
                        'unit_price' => (float) $addon->price,
                        'discount' => 0.00,
                    ];
                });
        }

        return $lines;
    }

    /**
     * Create a new commercial quotation for a lead.
     */
    public function createQuotation(Lead $lead, array $data, array $items, ?User $user = null): CompanyQuotation
    {
        return DB::transaction(function () use ($lead, $data, $items, $user) {
            $numberService = app(QuotationNumberService::class);

            /*
             * A deal has at most one active quotation. Raising a fresh one is
             * rare — reps normally revise — but nothing stopped a second
             * "Draft" quotation from sitting beside a live one, and the deal
             * page found itself with two things claiming to be the current
             * quote. Anything still active is superseded by the new one — a
             * financially locked quotation is left alone, the same escape
             * hatch `reviseQuotation()` respects: it is a settled record, not
             * a draft this new one replaces.
             */
            $lead->quotations()
                ->get()
                ->filter(fn (CompanyQuotation $q) => $q->isActive() && ! $q->isFinanciallyLocked())
                ->each(fn (CompanyQuotation $q) => $q->forceFill(['status' => QuotationStatus::Superseded])->save());

            /*
             * Quotations are prepared by an employee, and `prepared_by_id` is
             * not nullable, so a rep who has no employee record yet needs one.
             *
             * The fallback keyed that record on `organization_id`, a column
             * `company_employees` does not have — so every rep without an
             * employee row got a 500 instead of a quotation. `user_id` is the
             * column that exists and the identity that actually matters.
             */
            $employee = $user
                ? CompanyEmployee::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'employee_code' => 'EMP-'.Str::random(5),
                        'employment_status' => 'active',
                    ]
                )
                : CompanyEmployee::query()->firstOrFail();

            $baseAttributes = [
                'organization_id' => $lead->organization_id,
                'version' => 1,
                'lead_id' => $lead->id,
                'company_plan_id' => $data['company_plan_id'] ?? null,
                'billing_cycle' => $data['billing_cycle'] ?? BillingCycle::Monthly->value,
                'prepared_by_id' => $employee->id,
                'assigned_id' => $lead->assigned_user_id ? ($employee->id) : null,
                'status' => QuotationStatus::Draft,
                'valid_until' => ! empty($data['valid_until']) ? Carbon::parse($data['valid_until']) : now()->addDays(30),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? config('commercial.default_terms.general'),
                'payment_terms' => $data['payment_terms'] ?? config('commercial.default_terms.payment'),
                'implementation_terms' => $data['implementation_terms'] ?? config('commercial.default_terms.implementation'),
                'support_terms' => $data['support_terms'] ?? config('commercial.default_terms.support'),
                'cancellation_terms' => $data['cancellation_terms'] ?? config('commercial.default_terms.cancellation'),
                /*
                 * Left null unless a caller explicitly names a rate:
                 * `saveItemsAndCalculateTotals()` stamps the configured
                 * default onto the row the first time it computes totals, so
                 * the rate this quotation was actually priced at is always
                 * on record rather than re-read from config every time.
                 */
                'gst_rate' => isset($data['gst_rate']) ? (float) $data['gst_rate'] : null,
            ];

            [$quotation, $quotationNumber] = $this->saveQuotationWithUniqueNumber($numberService, $baseAttributes);

            /*
             * A selected package/add-ons/additional-users become concrete
             * line items *before* any custom ones the rep typed — the
             * package is the anchor a salesperson works from, custom lines
             * are what they add on top. Each one snapshots its own title and
             * price at this exact moment; nothing here is re-read later.
             */
            $packageItems = $this->buildPackageLineItems(
                isset($data['company_plan_id']) ? (int) $data['company_plan_id'] : null,
                max(0, (int) ($data['additional_users'] ?? 0)),
                $data['addon_ids'] ?? [],
            );

            $this->saveItemsAndCalculateTotals($quotation, [...$packageItems, ...$items]);

            // Auto advance Lead stage to Negotiation
            $this->transitionStage($lead, LeadStatus::Negotiation->value, $user, "Commercial quotation {$quotationNumber} generated in Draft mode.");

            // Log canonical Activity
            $lead->activities()->create([
                'user_id' => $user?->id,
                'type' => ActivityType::Quotation,
                'description' => "Quotation {$quotationNumber} created (Grand Total: ₹ ".number_format($quotation->grand_total, 2).').',
                'properties' => [
                    'quotation_id' => $quotation->id,
                    'quotation_number' => $quotationNumber,
                    'grand_total' => $quotation->grand_total,
                ],
            ]);

            return $quotation->fresh(['items', 'lead']);
        });
    }

    /**
     * Insert a new quotation under a freshly-generated number, retrying past
     * a collision instead of letting one reach the caller.
     *
     * `QuotationNumberService::generateNextNumber()`'s `lockForUpdate()`
     * serializes concurrent callers against the latest existing row for a
     * prefix — but the very first quotation of a prefix has no row to lock,
     * so two requests racing to create it can still both compute `0001`.
     * Rather than a second locking mechanism for that one row, this retries
     * against the table's own unique constraint on
     * `[quotation_number, version]`: a collision fails this specific insert
     * with a `QueryException`, not the whole request, and the next attempt
     * generates against the row the losing attempt just committed.
     *
     * @param  array<string, mixed>  $attributes  Every quotation column except `quotation_number`.
     * @return array{0: CompanyQuotation, 1: string}
     */
    protected function saveQuotationWithUniqueNumber(QuotationNumberService $numberService, array $attributes): array
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $quotationNumber = $numberService->generateNextNumber();
            $quotation = new CompanyQuotation($attributes + ['quotation_number' => $quotationNumber]);

            try {
                $quotation->save();

                return [$quotation, $quotationNumber];
            } catch (QueryException $e) {
                $isDuplicateNumber = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'quotation_number');

                if (! $isDuplicateNumber || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not allocate a unique quotation number.');
    }

    /**
     * Create a new revised version (v2, v3) of a quotation preserving history immutably.
     */
    public function reviseQuotation(CompanyQuotation $parentQuotation, array $items, ?array $data = null, ?User $user = null): CompanyQuotation
    {
        app(QuotationStateMachine::class)->ensure($parentQuotation, 'revise');

        return DB::transaction(function () use ($parentQuotation, $items, $data, $user) {
            $baseParentId = $parentQuotation->parent_id ?: $parentQuotation->id;
            $maxVersion = CompanyQuotation::where('quotation_number', $parentQuotation->quotation_number)->max('version');

            $employee = CompanyEmployee::where('user_id', $user?->id)->first() ?? $parentQuotation->preparedBy;

            $revised = new CompanyQuotation([
                'organization_id' => $parentQuotation->organization_id,
                'quotation_number' => $parentQuotation->quotation_number,
                'version' => $maxVersion + 1,
                'parent_id' => $baseParentId,
                'lead_id' => $parentQuotation->lead_id,
                'company_plan_id' => $data['company_plan_id'] ?? $parentQuotation->company_plan_id,
                'billing_cycle' => $data['billing_cycle'] ?? $parentQuotation->billing_cycle?->value,
                'clinic_id' => $parentQuotation->clinic_id,
                'prepared_by_id' => $employee?->id ?? $parentQuotation->prepared_by_id,
                'assigned_id' => $parentQuotation->assigned_id,
                'status' => QuotationStatus::Draft,
                'valid_until' => ! empty($data['valid_until']) ? Carbon::parse($data['valid_until']) : $parentQuotation->valid_until,
                'notes' => $data['notes'] ?? $parentQuotation->notes,
                'terms' => $data['terms'] ?? $parentQuotation->terms,
                // The rate a revision is priced at follows the quotation it
                // revises, not the global default — a customer already quoted
                // at a given rate does not see it change because the config
                // moved on. A caller may still override it explicitly.
                'gst_rate' => isset($data['gst_rate']) ? (float) $data['gst_rate'] : $parentQuotation->gst_rate,
            ]);

            $revised->save();

            $this->saveItemsAndCalculateTotals($revised, $items);

            /*
             * The version being replaced is retired, not left to sit beside
             * its successor claiming to be current too. Two cases keep their
             * own status instead: one already further along than "still
             * being worked" (rejected, expired, or the one that won the
             * deal), and one that is financially locked — money has already
             * settled against it and an invoice raised, which makes it a
             * permanent record rather than a draft being replaced. Revising
             * a locked quotation is the escape hatch `CommercialTermsLockTest`
             * documents: the new version is a fresh proposal that exists
             * *alongside* the settled one, not a supersession of it.
             */
            if ($parentQuotation->isActive() && ! $parentQuotation->isFinanciallyLocked()) {
                $parentQuotation->forceFill(['status' => QuotationStatus::Superseded])->save();
            }

            $lead = $parentQuotation->lead;
            if ($lead) {
                $lead->activities()->create([
                    'user_id' => $user?->id,
                    'type' => ActivityType::Quotation,
                    'description' => "Quotation {$revised->quotation_number} revised to v{$revised->version} (Grand Total: ₹ ".number_format($revised->grand_total, 2).').',
                    'properties' => [
                        'quotation_id' => $revised->id,
                        'quotation_number' => $revised->quotation_number,
                        'version' => $revised->version,
                        'parent_id' => $baseParentId,
                    ],
                ]);
            }

            return $revised->fresh(['items', 'lead']);
        });
    }

    /**
     * Send commercial quotation to client and advance stage to QuotationSent.
     */
    public function sendQuotation(CompanyQuotation $quotation, array $options = [], ?User $user = null): array
    {
        // State legality first: sending is only ever legal from Draft.
        app(QuotationStateMachine::class)->ensure($quotation, 'send');

        // Discount approval is enforced here rather than in the controller so
        // that every send path — UI, command, future API — passes the same gate.
        $approvals = app(QuotationApprovalService::class);

        if (! $approvals->canBeSent($quotation)) {
            throw ValidationException::withMessages([
                'quotation' => $approvals->blockedReason($quotation),
            ]);
        }

        return DB::transaction(function () use ($quotation, $user) {
            $quotation->status = QuotationStatus::Sent;
            $quotation->sent_at = now();
            $quotation->save();

            $lead = $quotation->lead;
            if ($lead) {
                // Advance Lead stage to QuotationSent
                $this->transitionStage($lead, LeadStatus::QuotationSent->value, $user, "Commercial proposal {$quotation->quotation_number} v{$quotation->version} sent to client.");

                // Follow-up task for sales rep
                Task::create([
                    'title' => "Proposal Follow-up: {$lead->company_name}",
                    'description' => "Follow up on proposal {$quotation->quotation_number} (₹ ".number_format($quotation->grand_total, 2).').',
                    'type' => TaskType::Call,
                    'status' => TaskStatus::Pending,
                    'priority' => TaskPriority::High,
                    'due_at' => now()->addDays(2),
                    'assigned_user_id' => $lead->assigned_user_id ?? $user?->id,
                    'creator_id' => $user?->id,
                    'taskable_type' => $lead->getMorphClass(),
                    'taskable_id' => $lead->id,
                ]);

                // Canonical Activity Entry
                $lead->activities()->create([
                    'user_id' => $user?->id,
                    'type' => ActivityType::Quotation,
                    'description' => "Quotation {$quotation->quotation_number} (v{$quotation->version}) sent to client.",
                    'properties' => [
                        'quotation_id' => $quotation->id,
                        'quotation_number' => $quotation->quotation_number,
                        'version' => $quotation->version,
                    ],
                ]);
            }

            // Automated WhatsApp dispatch check: BLOCKED because quotation_sent_v1 template is not approved
            $whatsappStatus = 'blocked_template_not_approved';

            return [
                'success' => true,
                'quotation' => $quotation->fresh(['lead']),
                'whatsapp_status' => $whatsappStatus,
                'message' => "Quotation {$quotation->quotation_number} marked sent and stage updated to Quotation Sent.",
            ];
        });
    }

    /**
     * Approve quotation inside CRM console and advance stage to QuotationApproved.
     */
    public function approveQuotation(CompanyQuotation $quotation, ?User $user = null, ?string $notes = null): CompanyQuotation
    {
        app(QuotationStateMachine::class)->ensure($quotation, 'approve');

        $fresh = DB::transaction(function () use ($quotation, $user, $notes) {
            $quotation->status = QuotationStatus::Approved;
            $quotation->approved_at = now();
            if ($notes) {
                $quotation->notes = ($quotation->notes ? $quotation->notes."\n" : '').'Approval Note: '.$notes;
            }
            $quotation->save();

            $lead = $quotation->lead;
            if ($lead) {
                $this->transitionStage($lead, LeadStatus::QuotationApproved->value, $user, "Quotation {$quotation->quotation_number} v{$quotation->version} approved.");

                $lead->activities()->create([
                    'user_id' => $user?->id,
                    'type' => ActivityType::Quotation,
                    'description' => "Quotation {$quotation->quotation_number} approved.",
                    'properties' => [
                        'quotation_id' => $quotation->id,
                        'approved_at' => $quotation->approved_at->toDateTimeString(),
                    ],
                ]);
            }

            return $quotation->fresh(['lead']);
        });

        // Outside the transaction: the decision has already committed, and a
        // notification failure must never roll it back.
        $this->notifyQuotationApprovalDecision($fresh, 'approved', $user);

        return $fresh;
    }

    /**
     * Reject quotation and record rejection reason.
     */
    public function rejectQuotation(CompanyQuotation $quotation, string $reason, ?User $user = null): CompanyQuotation
    {
        app(QuotationStateMachine::class)->ensure($quotation, 'reject');

        $fresh = DB::transaction(function () use ($quotation, $reason, $user) {
            $quotation->status = QuotationStatus::Rejected;
            $quotation->rejected_at = now();
            $quotation->rejection_reason = $reason;
            $quotation->save();

            $lead = $quotation->lead;
            if ($lead) {
                $lead->activities()->create([
                    'user_id' => $user?->id,
                    'type' => ActivityType::Quotation,
                    'description' => "Quotation {$quotation->quotation_number} rejected by client. Reason: {$reason}",
                    'properties' => [
                        'quotation_id' => $quotation->id,
                        'rejection_reason' => $reason,
                    ],
                ]);
            }

            return $quotation->fresh(['lead']);
        });

        $this->notifyQuotationApprovalDecision($fresh, 'rejected', $user);

        return $fresh;
    }

    /**
     * Issue a Proforma Invoice for an approved quotation.
     *
     * A proforma is a request for payment, not a tax document — it only makes
     * sense between "approved" and "settled". Once a real Tax Invoice exists
     * (`CommercialLedgerService::generateInvoice()` raises one the moment the
     * quotation is paid in full), the proforma's job is done; issuing one
     * after that would put an informal request for payment next to a legal
     * one for the same money, so this refuses rather than overwriting.
     *
     * The number is derived deterministically from the quotation's own
     * (already uniquely, concurrency-safely allocated) `quotation_number`,
     * so no separate locking sequence is needed here — two concurrent calls
     * for the same quotation compute the identical string rather than racing
     * for different ones.
     */
    public function issueProforma(CompanyQuotation $quotation, ?User $user = null): CompanyQuotation
    {
        if ($quotation->status !== QuotationStatus::Approved) {
            throw new InvalidArgumentException('A proforma invoice can only be issued for an approved quotation.');
        }

        if (CommercialInvoice::where('company_quotation_id', $quotation->id)->exists()) {
            throw new InvalidArgumentException('This quotation has already been settled and invoiced; use the tax invoice instead of a proforma.');
        }

        if ($quotation->proforma_number) {
            return $quotation;
        }

        return DB::transaction(function () use ($quotation, $user) {
            $quotation->update([
                'proforma_number' => 'PI-'.Str::after($quotation->quotation_number, 'QT-'),
                'proforma_issued_at' => now(),
            ]);

            $quotation->lead?->activities()->create([
                'user_id' => $user?->id,
                'type' => ActivityType::Document,
                'description' => "Proforma invoice {$quotation->proforma_number} issued for {$quotation->quotation_number}.",
                'properties' => [
                    'quotation_id' => $quotation->id,
                    'proforma_number' => $quotation->proforma_number,
                ],
            ]);

            return $quotation->fresh(['lead']);
        });
    }

    /**
     * Compute authoritative server-side line item calculations, GST included.
     *
     * `$tax` used to be a bare figure a caller passed in — never a rate, never
     * tied to a taxable base, and the create form never actually exposed a
     * field for a rep to set it, so every quotation was raised at ₹0 tax
     * regardless. GST is computed here instead, through the one canonical
     * service, from the quotation's own `gst_rate` (stamped once at creation)
     * and the seller/customer state on record — never re-derived by the PDF,
     * the printable view, or anywhere else.
     */
    public function saveItemsAndCalculateTotals(CompanyQuotation $quotation, array $items): void
    {
        if ($quotation->isFinanciallyLocked()) {
            throw new InvalidArgumentException(
                'This quotation has been settled and invoiced, so its line items are fixed. Revise it to quote something different.'
            );
        }

        $quotation->items()->delete();

        $subtotal = 0.00;
        $discount = 0.00;

        foreach ($items as $itemData) {
            $qty = max(1, (int) ($itemData['quantity'] ?? 1));
            $unitPrice = max(0.00, (float) ($itemData['unit_price'] ?? 0.00));
            $itemDiscount = max(0.00, (float) ($itemData['discount'] ?? 0.00));

            $lineTotal = max(0.00, ($qty * $unitPrice) - $itemDiscount);

            $quotation->items()->create([
                'title' => $itemData['title'] ?? 'Software Package',
                'description' => $itemData['description'] ?? null,
                'item_type' => $itemData['item_type'] ?? 'custom',
                'company_package_addon_id' => $itemData['company_package_addon_id'] ?? null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount' => $itemDiscount,
                'total' => $lineTotal,
            ]);

            $subtotal += ($qty * $unitPrice);
            $discount += $itemDiscount;
        }

        /*
         * A header discount set by a person survives a line-item edit.
         *
         * This used to recompute `discount` from the lines every single time,
         * so a negotiated headline figure could be typed in and then silently
         * destroyed by the next save. The line total remains the default; it is
         * only the default.
         */
        $effectiveDiscount = $quotation->discount_is_manual
            ? (float) $quotation->discount
            : $discount;

        /*
         * `setQuotationDiscount()` refuses a discount larger than the
         * subtotal it is set against; this path — every item create and
         * every item edit — did not, because it never occurred to a caller
         * that the *items* could shrink out from under a discount set
         * earlier. A manual ₹8,000 discount surviving a line-item removal
         * that drops the subtotal to ₹5,000 collapsed the taxable amount and
         * grand total to ₹0 while the stored discount still read ₹8,000 —
         * exactly the mismatched pair a customer's PDF has no way to catch.
         */
        if ($effectiveDiscount > $subtotal) {
            throw new InvalidArgumentException(
                'The discount on this quotation now exceeds its subtotal. Adjust the discount before changing these items.'
            );
        }

        $taxableAmount = max(0.00, $subtotal - $effectiveDiscount);

        $gstRate = $quotation->gst_rate !== null
            ? (float) $quotation->gst_rate
            : (float) config('commercial.default_gst_rate', 18.00);

        $breakdown = app(GstCalculationService::class)->calculate(
            $taxableAmount,
            Organization::provider()?->state,
            $quotation->lead?->state,
            $gstRate,
        );

        $grandTotal = max(0.00, $taxableAmount + $breakdown['total_tax']);

        /*
         * Line items decide the price, and the price is exactly what
         * approval signs off on. `setQuotationDiscount()` already revokes an
         * existing approval on a material change to the discount; editing
         * the items themselves reached `grand_total` by a different door
         * with no such check, so an already-approved (even already-sent)
         * quotation's total could change with nobody having to look at it
         * again.
         */
        $wasApproved = app(QuotationApprovalService::class)->isApproved($quotation);
        $totalChanged = abs((float) $quotation->grand_total - $grandTotal) > 0.001;
        $revoke = $wasApproved && $totalChanged;

        $quotation->update(array_merge([
            'subtotal' => $subtotal,
            'discount' => $effectiveDiscount,
            'taxable_amount' => $breakdown['taxable_amount'],
            'gst_rate' => $breakdown['gst_rate'],
            'tax_type' => $breakdown['tax_type'],
            'cgst_amount' => $breakdown['cgst_amount'],
            'sgst_amount' => $breakdown['sgst_amount'],
            'igst_amount' => $breakdown['igst_amount'],
            'tax' => $breakdown['total_tax'],
            'grand_total' => $grandTotal,
        ], $revoke ? [
            'approved_at' => null,
            'status' => $quotation->sent_at ? QuotationStatus::Sent : QuotationStatus::Draft,
        ] : []));
    }

    /**
     * Set the headline discount on a quotation, as either a percentage or a
     * fixed rupee amount.
     *
     * Three rules make this safe to expose.
     *
     * First, changing the discount on an approved quotation revokes that
     * approval. The gate exists so a rep cannot clear their own discount, and
     * without this it could be walked around in two moves: get a modest
     * discount approved, then raise it. The quotation returns to where it was
     * before approval — `sent` if it had gone to the customer, `draft` if not —
     * and has to be approved again.
     *
     * Second, a percentage discount is resolved to a rupee amount *before*
     * anything else runs — `discount` itself always stays the one figure GST,
     * the PDF, and the invoice snapshot already read, so nothing downstream
     * needs to know a percentage was ever involved.
     *
     * Third, `$user` is recorded as `discount_requested_by_id` and is what
     * `QuotationApprovalService` checks the tiered approval rule against —
     * an admin applying 15% needs no sign-off; an employee applying the same
     * 15% does, per the config threshold. This is evaluated here, once, at
     * the moment the discount is set, not re-derived later from whoever
     * happens to call `send()`.
     *
     * @return array{quotation: CompanyQuotation, approval_revoked: bool, requires_approval: bool}
     */
    public function setQuotationDiscount(CompanyQuotation $quotation, string $discountType, float $discountValue, ?User $user = null): array
    {
        if ($quotation->isFinanciallyLocked()) {
            throw new InvalidArgumentException(
                'This quotation has been settled and invoiced, so its terms are fixed. Revise it to quote a different price.'
            );
        }

        if (! in_array($discountType, ['percentage', 'fixed'], true)) {
            throw new InvalidArgumentException('Discount type must be either percentage or fixed.');
        }

        $discountValue = max(0.00, $discountValue);
        $subtotal = (float) $quotation->subtotal;

        if ($discountType === 'percentage') {
            if ($discountValue > 100) {
                throw new InvalidArgumentException('A percentage discount cannot exceed 100%.');
            }
            $discount = round($subtotal * $discountValue / 100, 2);
        } else {
            $discount = round($discountValue, 2);
        }

        if ($discount > $subtotal) {
            throw new InvalidArgumentException('A discount cannot exceed the quotation subtotal.');
        }

        $approvals = app(QuotationApprovalService::class);
        $wasApproved = $approvals->isApproved($quotation);

        /*
         * Only the resolved rupee amount matters here, not how it was
         * expressed — re-entering "10%" as "the same ₹1,500 it always was"
         * has not changed the commercial terms, so it must not revoke an
         * existing approval. Comparing `discount_type`/`discount_value`
         * instead would misfire the moment either field is unset (any
         * quotation whose discount was set before Phase 2B, or written
         * directly by a test fixture), since a null baseline reads as "always
         * changed" even when the amount is identical.
         */
        $changed = abs((float) $quotation->discount - $discount) > 0.001;
        $revoke = $wasApproved && $changed;

        $requiresApproval = $approvals->requiresApprovalForUser($user, $discount, $subtotal);

        /*
         * A discount changes the taxable base, so the GST breakdown must be
         * recalculated through the same canonical service used at creation —
         * carrying over the old flat tax amount would leave cgst/sgst/igst
         * stale relative to the new discounted total.
         */
        $taxableAmount = max(0.00, $subtotal - $discount);
        $gstRate = $quotation->gst_rate !== null ? (float) $quotation->gst_rate : (float) config('commercial.default_gst_rate', 18.00);
        $breakdown = app(GstCalculationService::class)->calculate($taxableAmount, Organization::provider()?->state, $quotation->lead?->state, $gstRate);
        $grandTotal = max(0.00, $taxableAmount + $breakdown['total_tax']);

        $quotation->update(array_merge([
            'discount' => $discount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_is_manual' => true,
            'discount_requested_by_id' => $user?->id,
            'taxable_amount' => $breakdown['taxable_amount'],
            'gst_rate' => $breakdown['gst_rate'],
            'tax_type' => $breakdown['tax_type'],
            'cgst_amount' => $breakdown['cgst_amount'],
            'sgst_amount' => $breakdown['sgst_amount'],
            'igst_amount' => $breakdown['igst_amount'],
            'tax' => $breakdown['total_tax'],
            'grand_total' => $grandTotal,
        ], $revoke ? [
            'approved_at' => null,
            'status' => $quotation->sent_at ? QuotationStatus::Sent : QuotationStatus::Draft,
        ] : []));

        $fresh = $quotation->fresh();

        $quotation->lead?->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Document,
            'description' => $revoke
                ? 'Discount changed to ₹'.number_format($discount).' on '.$fresh->quotation_number.' — approval revoked, needs signing off again.'
                : 'Discount set to ₹'.number_format($discount).' on '.$fresh->quotation_number.'.',
            'properties' => [
                'quotation_id' => $quotation->id,
                'discount' => $discount,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'requires_approval' => $requiresApproval,
                'approval_revoked' => $revoke,
            ],
        ]);

        /*
         * Only when the quotation is genuinely awaiting sign-off right now —
         * not on every edit while it sits below the threshold, and not a
         * second time for the same still-pending amount, since nothing here
         * changed what an approver would see.
         */
        if ($requiresApproval && ! $approvals->isApproved($fresh)) {
            $this->notifyQuotationApprovalRequested($fresh, $discount, $user);
        }

        return [
            'quotation' => $fresh,
            'approval_revoked' => $revoke,
            'requires_approval' => $requiresApproval,
        ];
    }

    /**
     * Tell every manager who can act on it that a quotation needs approval.
     */
    protected function notifyQuotationApprovalRequested(CompanyQuotation $quotation, float $discount, ?User $requester): void
    {
        $organizationId = $quotation->organization_id ?? $quotation->lead?->organization_id;

        if (! $organizationId) {
            return;
        }

        $company = $quotation->lead?->company_name ?? 'a customer';
        $requesterName = $requester?->name ?? 'A team member';

        $approvers = app(QuotationApprovalService::class)->approversFor($organizationId);

        foreach ($approvers as $approver) {
            $this->notifyUser(
                $approver->id,
                'Quotation discount needs approval',
                "{$requesterName} requested a ₹".number_format($discount)." discount on {$quotation->quotation_number} for {$company}.",
                NotificationPriority::HIGH,
                [
                    'type' => 'quotation_discount_approval_requested',
                    'quotation_id' => $quotation->id,
                    'requested_by' => $requester?->id,
                    'discount' => $discount,
                ],
                NotificationCategory::APPROVAL,
                [new NotificationActionDTO('Review quotation', route('company.crm.quotations.show', $quotation))],
            );
        }
    }

    /**
     * Tell whoever requested the discount what the manager decided.
     *
     * The requester is `discount_requested_by_id` — the person the tiered
     * approval rule actually evaluated — not simply whoever is assigned the
     * lead today, since ownership can change hands after the request was
     * made.
     */
    protected function notifyQuotationApprovalDecision(CompanyQuotation $quotation, string $decision, ?User $actor): void
    {
        $requesterId = $quotation->discount_requested_by_id;

        if (! $requesterId) {
            return;
        }

        $company = $quotation->lead?->company_name ?? 'a customer';
        $actorName = $actor?->name ?? 'A manager';

        $this->notifyUser(
            $requesterId,
            $decision === 'approved' ? 'Quotation discount approved' : 'Quotation discount rejected',
            "{$actorName} {$decision} the discount on {$quotation->quotation_number} for {$company}.",
            NotificationPriority::HIGH,
            [
                'type' => 'quotation_discount_approval_decided',
                'quotation_id' => $quotation->id,
                'decision' => $decision,
                'decided_by' => $actor?->id,
            ],
            NotificationCategory::APPROVAL,
            [new NotificationActionDTO('View quotation', route('company.crm.quotations.show', $quotation))],
        );
    }

    /**
     * Retrieve payload for Quotations Command Center workspace.
     */
    public function getQuotationWorkspacePayload(array $filters = [], ?User $user = null): array
    {
        $orgId = $user?->resolveOrganizationId();
        $isMasterAdmin = $user?->isMasterAdmin() ?? false;

        $query = CompanyQuotation::with(['lead', 'preparedBy.user', 'assignedTo', 'items'])
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->latest();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                    ->orWhereHas('lead', fn ($lq) => $lq->where('company_name', 'like', "%{$search}%")->orWhere('contact_name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['scoped_to_user']) && $user) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_id', $user->id)
                    ->orWhereHas('lead', fn ($lq) => $lq->where('assigned_user_id', $user->id));
            });
        }

        $allQuotations = (clone $query)->get();

        $metrics = [
            'total_proposals' => $allQuotations->count(),
            'total_pipeline_value' => '₹ '.number_format($allQuotations->sum('grand_total'), 2),
            'drafts_count' => $allQuotations->where('status', QuotationStatus::Draft)->count(),
            'sent_count' => $allQuotations->where('status', QuotationStatus::Sent)->count(),
            'negotiation_count' => $allQuotations->where('status', QuotationStatus::Negotiation)->count(),
            'approved_count' => $allQuotations->where('status', QuotationStatus::Approved)->count(),
            'approved_value' => '₹ '.number_format($allQuotations->where('status', QuotationStatus::Approved)->sum('grand_total'), 2),
            'expiring_soon_count' => $allQuotations->filter(fn ($q) => $q->valid_until && $q->valid_until->isFuture() && now()->diffInDays($q->valid_until) <= 7)->count(),
        ];

        $pickableLeads = Lead::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost])
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'contact_name']);

        /*
         * Approval state travels with the list, not just the detail page.
         *
         * An employee may discount up to the configured percentage on their
         * own; past it the proposal needs an approver. That rule was only ever
         * evaluated when someone opened a single quotation, so nothing in the
         * application could answer "what is waiting on me?" — approvals sat
         * unseen until the rep chased them by hand.
         */
        $approvals = app(QuotationApprovalService::class);
        $canApprove = $approvals->canApprove($user);

        $allQuotations->each(function (CompanyQuotation $quotation) use ($approvals, $canApprove) {
            $quotation->setAttribute('discount_percent', round($approvals->discountPercent($quotation), 2));
            $quotation->setAttribute('requires_approval', $approvals->requiresApproval($quotation));
            $quotation->setAttribute('is_approved', $approvals->isApproved($quotation));
            $quotation->setAttribute('can_be_sent', $approvals->canBeSent($quotation));
            $quotation->setAttribute('viewer_can_approve', $canApprove);
        });

        return [
            'quotations' => $allQuotations,
            'metrics' => $metrics,
            'leads' => $pickableLeads,
            'filters' => $filters,
            'approvalThreshold' => $approvals->threshold(),
        ];
    }

    /**
     * CRM-7: Triage a Lost Lead with reason, competitor, and optional win-back schedule.
     */
    public function triageLostLead(Lead $lead, array $data, ?User $user = null): Lead
    {
        $lead->update([
            'lost_reason' => $data['lost_reason'] ?? $lead->lost_reason,
            'lost_competitor' => $data['lost_competitor'] ?? $lead->lost_competitor,
            'lost_at' => $lead->lost_at ?? now(),
        ]);

        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Note,
            'description' => "Lost Deal Triaged. Reason: {$lead->lost_reason?->value}".($lead->lost_competitor ? " • Competitor: {$lead->lost_competitor}" : ''),
            'properties' => [
                'lost_reason' => $lead->lost_reason?->value,
                'lost_competitor' => $lead->lost_competitor,
                'triage_notes' => $data['notes'] ?? null,
            ],
        ]);

        if (! empty($data['winback_date'])) {
            $this->scheduleWinBack($lead, $data['winback_date'], $data['winback_notes'] ?? null, $user);
        }

        return $lead->fresh(['assignedUser', 'activities']);
    }

    /**
     * CRM-7: Schedule Win-Back task for a lost lead.
     */
    public function scheduleWinBack(Lead $lead, string $date, ?string $notes = null, ?User $user = null): Task
    {
        $dueDate = Carbon::parse($date);
        $lead->update(['winback_scheduled_at' => $dueDate]);

        // Idempotent: re-scheduling a win-back moves the existing open task
        // rather than stacking a second one on the rep's queue.
        $existing = Task::where('taskable_type', $lead->getMorphClass())
            ->where('taskable_id', $lead->id)
            ->where('type', TaskType::FollowUp)
            ->where('status', TaskStatus::Pending)
            ->where('title', 'like', 'Win-Back Follow-up:%')
            ->first();

        if ($existing) {
            $existing->update([
                'due_at' => $dueDate,
                'description' => "Scheduled win-back re-engagement for lost lead {$lead->company_name}. ".($notes ?? ''),
            ]);

            $lead->activities()->create([
                'user_id' => $user?->id,
                'type' => ActivityType::FollowUp,
                'description' => "Win-back follow-up rescheduled to {$dueDate->format('d M Y')}",
            ]);

            return $existing->fresh();
        }

        $task = Task::create([
            'organization_id' => $lead->organization_id,
            'assigned_user_id' => $lead->assigned_user_id ?? $user?->id,
            'taskable_type' => $lead->getMorphClass(),
            'taskable_id' => $lead->id,
            'type' => TaskType::FollowUp,
            'title' => "Win-Back Follow-up: {$lead->company_name}",
            'description' => "Scheduled win-back re-engagement for lost lead {$lead->company_name}. ".($notes ?? ''),
            'due_at' => $dueDate,
            'status' => TaskStatus::Pending,
            'priority' => 'high',
        ]);

        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::FollowUp,
            'description' => "Win-back follow-up scheduled for {$dueDate->format('d M Y')}",
        ]);

        return $task;
    }

    /**
     * Fire due win-back re-engagements.
     *
     * Closes the loop that `winback_scheduled_at` previously left open: the
     * date was stored and a task created, but nothing ever told the rep the
     * moment had arrived. Notifications are de-duplicated per lead so repeated
     * runs cannot spam the queue.
     *
     * @return array{notified:int, leads:int}
     */
    public function processDueWinBacks(?int $organizationId = null): array
    {
        $leads = Lead::with(['assignedUser'])
            ->whereNotNull('winback_scheduled_at')
            ->whereDate('winback_scheduled_at', '<=', now()->toDateString())
            ->where('status', LeadStatus::Lost)
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->get();

        $notified = 0;

        foreach ($leads as $lead) {
            $ownerId = $lead->assigned_user_id;

            if ($ownerId) {
                $alreadyNotified = EnterpriseNotification::where('notifiable_id', $ownerId)
                    ->where('notifiable_type', User::class)
                    ->where('data->metadata->lead_id', $lead->id)
                    ->where('data->metadata->type', 'winback_due')
                    ->exists();

                if (! $alreadyNotified) {
                    $this->notifyUser(
                        $ownerId,
                        'Win-Back Due',
                        "Scheduled win-back for {$lead->company_name} is due. Re-engage the client to reopen the deal.",
                        NotificationPriority::HIGH,
                        ['type' => 'winback_due', 'lead_id' => $lead->id]
                    );
                    $notified++;
                }
            }

            $alreadyLogged = $lead->activities()
                ->where('description', 'like', 'Win-back re-engagement due%')
                ->exists();

            if (! $alreadyLogged) {
                $lead->activities()->create([
                    'type' => ActivityType::FollowUp,
                    'description' => 'Win-back re-engagement due — rep notified.',
                    'properties' => ['winback_scheduled_at' => $lead->winback_scheduled_at?->toDateString()],
                ]);
            }
        }

        return ['notified' => $notified, 'leads' => $leads->count()];
    }

    /**
     * CRM-7: Get Sales Operations & Telemetry Payload.
     */
    public function getSalesOperationsPayload(?User $user = null, array $filters = []): array
    {
        $orgId = $user?->resolveOrganizationId();
        $isMasterAdmin = $user?->isMasterAdmin() ?? false;

        /*
         * CrmScope, not a hand-rolled tenant filter.
         *
         * The two are identical for everyone who can open this page. Reaching it
         * needs `view-operations`, which is the first entry in
         * `CrmScope::MANAGER_PERMISSIONS`, so every caller is a manager: the
         * scope resolves to LEVEL_ALL for a master admin — unfiltered, as the
         * old `! $isMasterAdmin` guard produced — and to LEVEL_ORGANIZATION for
         * everyone else, which is the same `where organization_id` clause. The
         * assigned-only branch cannot be reached from here at all.
         *
         * Going through the canonical scope means this page cannot drift from
         * the rule the rest of the CRM follows if that rule changes.
         */
        $leadsQuery = CrmScope::leads($user)->with(['assignedUser']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $leadsQuery->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('lost_competitor', 'like', "%{$search}%");
            });
        }

        $allLeads = (clone $leadsQuery)->get();
        $lostLeads = $allLeads->where('status', LeadStatus::Lost);
        $wonLeads = $allLeads->where('status', LeadStatus::Won);

        // Competitor loss breakdown
        $competitorLoss = $lostLeads->filter(fn ($l) => ! empty($l->lost_competitor))
            ->groupBy('lost_competitor')
            ->map(fn ($group, $comp) => [
                'competitor' => $comp,
                'count' => $group->count(),
                'lost_mrr' => $group->sum('expected_mrr'),
            ])->values();

        // Loss reason breakdown
        $lossReasons = $lostLeads->filter(fn ($l) => ! empty($l->lost_reason))
            ->groupBy(fn ($l) => $l->lost_reason->value)
            ->map(fn ($group, $reason) => [
                'reason' => $reason,
                'count' => $group->count(),
                'lost_mrr' => $group->sum('expected_mrr'),
            ])->values();

        // Win-back pending leads
        $winbackLeads = $lostLeads->filter(fn ($l) => ! empty($l->winback_scheduled_at))->values();

        // Sales Rep Scorecards
        $reps = User::query()
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->get()
            ->map(function ($repUser) use ($allLeads) {
                $repLeads = $allLeads->where('assigned_user_id', $repUser->id);
                $totalAssigned = $repLeads->count();
                $wonCount = $repLeads->where('status', LeadStatus::Won)->count();
                $lostCount = $repLeads->where('status', LeadStatus::Lost)->count();
                $closedCount = $wonCount + $lostCount;
                $winRate = $closedCount > 0 ? round(($wonCount / $closedCount) * 100, 1) : 0;

                return [
                    'id' => $repUser->id,
                    'name' => $repUser->name,
                    'email' => $repUser->email,
                    'total_leads' => $totalAssigned,
                    'won_count' => $wonCount,
                    'lost_count' => $lostCount,
                    'won_mrr' => $repLeads->where('status', LeadStatus::Won)->sum('expected_mrr'),
                    'win_rate' => $winRate,
                ];
            })->filter(fn ($r) => $r['total_leads'] > 0)->values();

        /*
         * Stale leads used to be gathered here — a hundred rows mapped on every
         * request, sent as `staleLeads`, and rendered by nothing: the workspace
         * has never declared that prop. The count derived from it was wrong as
         * well, because the list was capped at a hundred and the cap became the
         * answer.
         *
         * The Overview reports them properly, with an uncapped count and a link
         * to the Deals cut that lists exactly the rows it counted.
         */
        $metrics = [
            'total_leads' => $allLeads->count(),
            'total_won' => $wonLeads->count(),
            'total_lost' => $lostLeads->count(),
            'overall_win_rate' => ($wonLeads->count() + $lostLeads->count()) > 0 ? round(($wonLeads->count() / ($wonLeads->count() + $lostLeads->count())) * 100, 1) : 0,
            'winback_scheduled_count' => $winbackLeads->count(),
            'total_lost_mrr' => $lostLeads->sum('expected_mrr'),
        ];

        return [
            'lostLeads' => $lostLeads->values(),
            'winbackLeads' => $winbackLeads,
            'competitorLoss' => $competitorLoss,
            'lossReasons' => $lossReasons,
            'repScorecards' => $reps,
            'metrics' => $metrics,
            'filters' => $filters,
        ];
    }

    /**
     * CRM-8: Get Organizations & Network Topology Payload.
     */
    public function getOrganizationsPayload(?User $user = null, array $filters = []): array
    {
        $orgId = $user?->resolveOrganizationId();
        $isMasterAdmin = $user?->isMasterAdmin() ?? false;

        $query = Organization::with(['assignedUser', 'license', 'branches', 'leads'])
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('id', $orgId))
            ->latest();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $allOrgs = (clone $query)->get();

        $metrics = [
            'total_organizations' => $allOrgs->count(),
            'healthy_count' => $allOrgs->filter(fn ($o) => ($o->health_score ?? 100) >= 80)->count(),
            'expansion_count' => $allOrgs->filter(fn ($o) => (bool) $o->expansion_opportunity)->count(),
            'at_risk_count' => $allOrgs->filter(fn ($o) => ($o->health_score ?? 100) < 50)->count(),
        ];

        return [
            'organizations' => $allOrgs,
            'metrics' => $metrics,
            'filters' => $filters,
        ];
    }

    /**
     * CRM-8: Get Subscriptions & Revenue Engine Payload.
     */
    public function getSubscriptionsPayload(?User $user = null, array $filters = []): array
    {
        $orgId = $user?->resolveOrganizationId();
        $isMasterAdmin = $user?->isMasterAdmin() ?? false;

        $query = CompanySubscription::with(['plan'])
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->latest();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('organization', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%");
            });
        }

        $allSubs = (clone $query)->get();

        $metrics = [
            'total_subscriptions' => $allSubs->count(),
            'active_count' => $allSubs->where('status', 'active')->count(),
            'expiring_soon_count' => $allSubs->filter(fn ($s) => $s->ends_at && $s->ends_at->isFuture() && now()->diffInDays($s->ends_at) <= 30)->count(),
            'total_mrr' => $allSubs->sum(fn ($s) => $s->max_users * 1500),
            'total_arr' => $allSubs->sum(fn ($s) => $s->max_users * 18000),
        ];

        return [
            'subscriptions' => $allSubs,
            'metrics' => $metrics,
            'filters' => $filters,
        ];
    }

    /**
     * CRM-8: Get Renewals Radar Payload.
     */
    public function getRenewalsPayload(?User $user = null, array $filters = []): array
    {
        $orgId = $user?->resolveOrganizationId();
        $isMasterAdmin = $user?->isMasterAdmin() ?? false;

        $query = CompanySubscription::with(['plan'])
            ->when($orgId && ! $isMasterAdmin, fn ($q) => $q->where('organization_id', $orgId))
            ->whereNotNull('ends_at')
            ->latest('ends_at');

        $allRenewals = (clone $query)->get();

        $metrics = [
            'due_this_month' => $allRenewals->filter(fn ($s) => $s->ends_at->isFuture() && now()->diffInDays($s->ends_at) <= 30)->count(),
            'at_risk_renewals' => $allRenewals->filter(fn ($s) => $s->status === 'pending_renewal' || $s->status === 'overdue')->count(),
            'expected_renewal_mrr' => $allRenewals->sum(fn ($s) => $s->max_users * 1500),
            'retention_rate' => 96.5,
        ];

        return [
            'renewals' => $allRenewals,
            'metrics' => $metrics,
            'filters' => $filters,
        ];
    }
}
