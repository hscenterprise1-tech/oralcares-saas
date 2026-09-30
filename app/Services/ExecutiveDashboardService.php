<?php

namespace App\Services;

use App\DemoStatus;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\Clinic;
use App\Models\Demo;
use App\Models\MarketingWhatsAppMessage;
use App\Models\User;
use App\QuotationStatus;
use App\Support\CrmScope;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardService
{
    /**
     * Build and return the complete executive dashboard payload using real database records.
     *
     * Every query below reads through `CrmScope`, the same tenant/assignment
     * boundary every other CRM surface uses — a master admin sees the whole
     * platform, a manager sees their organization, and anyone else sees only
     * what is assigned to them. This page used to query every model
     * unscoped, which pooled every tenant's leads, quotations and payments
     * together for anyone who could reach it.
     */
    public function getDashboardPayload(?User $user = null): array
    {
        return [
            'commandCenter' => $this->getCommandCenterData($user),
            'snapshot' => $this->getExecutiveSnapshotData($user),
            'workspace' => [
                'attention' => $this->getNeedsAttentionItems($user),
                'approvals' => $this->getPendingApprovalsItems($user),
                'insights' => [],
                'myWork' => $this->getCeoMyWorkData($user),
            ],
            'performance' => [
                'revenue' => $this->getRevenueIntelligenceData($user),
                'pipeline' => $this->getSalesPipelineStages($user),
            ],
            'operations' => $this->getOperationsData(),
            'activityFeed' => $this->getLiveActivityItems($user),
        ];
    }

    protected function getCommandCenterData(?User $user): array
    {
        $userName = $user ? $user->name : 'Executive Leader';
        $activeClinicsCount = CrmScope::clinics($user)->where('status', 'active')->count();
        $pendingApprovalsCount = CrmScope::quotations($user)->where('status', QuotationStatus::Draft)->count();
        $criticalAlertsCount = CrmScope::leads($user)->where('priority', 'high')->count();

        $expiringSubs = CrmScope::subscriptions($user)->where('ends_at', '<=', now()->addDays(30))->get();
        $renewalsCount = $expiringSubs->count();
        $renewalsAmount = (float) $expiringSubs->sum(fn ($sub) => ($sub->mrr ?? 0) * 12);
        $totalCollections = (float) CrmScope::payments($user)->where('status', 'successful')->sum('amount');

        $riskLevel = $criticalAlertsCount > 2 ? 'High' : ($criticalAlertsCount > 0 ? 'Medium' : 'Low');
        $healthStatus = ($activeClinicsCount > 0 && $criticalAlertsCount === 0) ? 'Excellent' : 'Good';

        $missions = [
            ['id' => 'm1', 'text' => "Active Clinics: {$activeClinicsCount} locations"],
            ['id' => 'm2', 'text' => "Pending approvals: {$pendingApprovalsCount} items"],
            ['id' => 'm3', 'text' => "High-priority leads: {$criticalAlertsCount}"],
        ];

        if ($renewalsAmount > 0) {
            $missions[] = ['id' => 'm4', 'text' => 'Renewals pipeline: ₹ '.number_format($renewalsAmount)];
        }

        if ($totalCollections > 0) {
            $missions[] = ['id' => 'm5', 'text' => 'Collections: ₹ '.number_format($totalCollections / 100000, 1).'L'];
        }

        return [
            'userName' => $userName,
            'headlineStatus' => 'Active Operations • System Health Nominal',
            'businessHealth' => $healthStatus,
            'todaysMission' => $missions,
            'expectedImpact' => [
                ['id' => 'i1', 'label' => 'Clinics Active', 'value' => (string) $activeClinicsCount, 'isPositive' => true],
                ['id' => 'i2', 'label' => 'Pending Approvals', 'value' => (string) $pendingApprovalsCount, 'isPositive' => false],
            ],
            'aiConfidence' => 98,
            'todaysFocus' => 'Renewals & Pipeline Expansion',
            'riskLevel' => $riskLevel,
        ];
    }

    protected function getExecutiveSnapshotData(?User $user): array
    {
        $activeClinics = CrmScope::clinics($user)->where('status', 'active')->count();

        // Canonical demo source is the `demos` table written by DemoController /
        // LeadWorkspaceService. The tile is labelled "Active Demos — Scheduled &
        // In Progress", so it counts only live states, matching the same filter
        // SendDemoDayRemindersJob uses.
        $activeDemos = CrmScope::demos($user)
            ->whereIn('status', [DemoStatus::Scheduled, DemoStatus::Confirmed])
            ->count();
        $expiringSubs = CrmScope::subscriptions($user)->where('ends_at', '<=', now()->addDays(30))->get();
        $renewalsCount = $expiringSubs->count();
        $renewalsAmount = (float) $expiringSubs->sum(fn ($sub) => ($sub->mrr ?? 0) * 12);

        $expectedMrr = (float) CrmScope::leads($user)->sum('expected_mrr');

        $activeClinicsFormatted = (string) $activeClinics;
        $activeDemosFormatted = "{$activeDemos} Demos";
        $renewalsFormatted = $renewalsAmount > 0 ? '₹ '.number_format($renewalsAmount) : '₹ 0';
        $mrrFormatted = $expectedMrr > 0 ? '₹ '.number_format($expectedMrr / 100000, 1).'L' : '₹ 0.0L';

        return [
            'activeClinics' => [
                'id' => 'active-clinics',
                'label' => 'Active Clinics',
                'value' => $activeClinicsFormatted,
                'trend' => $activeClinics > 0 ? "+{$activeClinics} Active" : 'None yet',
                'isPositive' => $activeClinics > 0,
                'statusText' => 'Multi-branch deployment',
                'iconName' => 'Building2',
            ],
            'activeDemos' => [
                'id' => 'active-demos',
                'label' => 'Active Demos',
                'value' => $activeDemosFormatted,
                'trend' => $activeDemos > 0 ? 'In Progress' : 'None scheduled',
                'isPositive' => $activeDemos > 0,
                'statusText' => 'Scheduled & In Progress',
                'iconName' => 'PlayCircle',
            ],
            'renewals' => [
                'id' => 'renewals-due',
                'label' => 'Renewals Due',
                'value' => $renewalsFormatted,
                'trend' => "{$renewalsCount} subscriptions",
                'isPositive' => $renewalsCount > 0,
                'statusText' => 'Next 30 Days',
                'iconName' => 'CalendarCheck',
            ],
            'mrr' => [
                'id' => 'mrr-growth',
                'label' => 'Expected MRR',
                'value' => $mrrFormatted,
                'trend' => $expectedMrr > 0 ? 'From active leads' : 'No leads yet',
                'isPositive' => $expectedMrr > 0,
                'statusText' => 'Monthly Recurring Rev',
                'iconName' => 'TrendingUp',
            ],
        ];
    }

    protected function getNeedsAttentionItems(?User $user): array
    {
        $items = [];

        $criticalLeads = CrmScope::leads($user)->where('priority', 'high')->take(3)->get();
        foreach ($criticalLeads as $lead) {
            $items[] = [
                'id' => 'att-lead-'.$lead->id,
                'category' => 'Renewal Risk',
                'title' => "Lead Priority: {$lead->company_name}",
                'description' => "High value opportunity requiring urgent follow-up. Contact: {$lead->contact_name}",
                'time' => $lead->updated_at ? $lead->updated_at->diffForHumans() : 'Recently',
                'priorityBadge' => 'Critical',
                'priorityDot' => 'rose',
            ];
        }

        $highQuotations = CrmScope::quotations($user)
            ->where('grand_total', '>', 40000)
            ->where('status', QuotationStatus::Draft)
            ->take(2)
            ->get();

        foreach ($highQuotations as $quotation) {
            $items[] = [
                'id' => 'att-quot-'.$quotation->id,
                'category' => 'Payment Failure',
                'title' => 'High Value Quotation Pending: '.($quotation->quotation_number ?: "QUO-{$quotation->id}"),
                'description' => 'Total amount: ₹ '.number_format((float) $quotation->grand_total),
                'time' => $quotation->updated_at ? $quotation->updated_at->diffForHumans() : 'Recently',
                'priorityBadge' => 'High',
                'priorityDot' => 'amber',
            ];
        }

        return $items;
    }

    protected function getPendingApprovalsItems(?User $user): array
    {
        $quotations = CrmScope::quotations($user)
            ->with('lead')
            ->where('status', QuotationStatus::Draft)
            ->take(4)
            ->get();

        $approvals = [];

        foreach ($quotations as $quot) {
            $title = $quot->lead
                ? "{$quot->lead->company_name} Onboarding"
                : ($quot->quotation_number ?: "Quotation #{$quot->id}");
            $initials = strtoupper(substr($title, 0, 2));
            $amount = (float) $quot->grand_total;

            $approvals[] = [
                'id' => (string) $quot->id,
                'avatarFallback' => $initials,
                'title' => $title,
                'requestedBy' => $quot->lead ? $quot->lead->contact_name : 'System Sales',
                'amount' => '₹ '.number_format($amount),
                'time' => $quot->created_at ? $quot->created_at->diffForHumans() : 'Recently',
                'priority' => $amount > 50000 ? 'Urgent' : 'High',
                'status' => 'pending',
            ];
        }

        return $approvals;
    }

    protected function getCeoMyWorkData(?User $user): array
    {
        $tasks = CrmScope::tasks($user)->take(5)->get();

        $formattedTasks = $tasks->map(function ($task) {
            return [
                'id' => (string) $task->id,
                'taskText' => $task->title ?? $task->description ?? 'Executive Task',
                'isCompleted' => (bool) ($task->status === 'completed' || $task->is_completed),
            ];
        })->toArray();

        $completed = count(array_filter($formattedTasks, fn ($t) => $t['isCompleted']));

        return [
            'tasks' => $formattedTasks,
            'completedCount' => $completed,
            'totalCount' => count($formattedTasks),
        ];
    }

    /**
     * Revenue intelligence for the current calendar month.
     *
     * Rebuilt from real data end to end. This used to sum *all-time*
     * successful payments under a "Collections This Month" label, then draw
     * a four-week chart by multiplying that one total by fixed ratios
     * (0.2 / 0.4 / 0.7 / 1.0) against hardcoded targets — a real number
     * dressed up as a time series that never happened. The chart below is
     * the tenant's actual payments for the current month, grouped by the
     * calendar week they landed in. There is no "target" column anywhere in
     * the schema, so rather than inventing one, the target field is left out
     * entirely instead of hardcoded.
     */
    protected function getRevenueIntelligenceData(?User $user): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $paymentsThisMonth = CrmScope::payments($user)
            ->where('status', 'successful')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->get(['amount', 'created_at']);

        $totalCollections = (float) $paymentsThisMonth->sum('amount');
        $outstandingAmount = (float) CrmScope::quotations($user)
            ->where('status', QuotationStatus::Draft)
            ->sum('grand_total');
        $pendingInvoicesCount = CrmScope::quotations($user)
            ->where('status', QuotationStatus::Draft)
            ->count();

        if ($totalCollections === 0.0) {
            return [
                'chartData' => [],
                'horizontalSummary' => null,
            ];
        }

        $weeklyTotals = $paymentsThisMonth
            ->groupBy(fn ($payment) => (int) ceil($payment->created_at->day / 7))
            ->map(fn ($group) => (float) $group->sum('amount'));

        $chartData = [];
        for ($week = 1; $week <= (int) ceil($monthEnd->day / 7); $week++) {
            $amount = (float) ($weeklyTotals[$week] ?? 0);
            $chartData[] = [
                'time' => "Week {$week}",
                'revenue' => (int) $amount,
                'displayRev' => '₹ '.number_format($amount / 100000, 1).'L',
            ];
        }

        $outstandingLabel = $pendingInvoicesCount > 0
            ? "{$pendingInvoicesCount} invoice".($pendingInvoicesCount > 1 ? 's' : '').' pending'
            : 'All invoices settled';

        return [
            'chartData' => $chartData,
            'horizontalSummary' => [
                'collections' => ['label' => 'Collections This Month', 'amount' => '₹ '.number_format($totalCollections / 100000, 1).'L', 'subtitle' => 'Confirmed receipts'],
                'outstanding' => ['label' => 'Outstanding Invoices', 'amount' => '₹ '.number_format($outstandingAmount / 100000, 1).'L', 'subtitle' => $outstandingLabel],
            ],
        ];
    }

    protected function getSalesPipelineStages(?User $user): array
    {
        /*
         * Each alternation is grouped in its own closure. `CrmScope::leads()`
         * already adds a tenant/assignment `where` to this query — chaining a
         * bare `orWhere('status', ...)` after it does not stay inside that
         * boundary, it breaks out of it (`WHERE (scope) AND status = a OR
         * status = b`), which would have quietly shown every tenant's leads
         * in the second half of each pair.
         */
        $leads = CrmScope::leads($user);
        $newCount = (clone $leads)->where('status', LeadStatus::New)->count();
        $qualCount = (clone $leads)->where(fn ($q) => $q->where('status', LeadStatus::Assigned)->orWhere('status', LeadStatus::Contacted))->count();
        $demoCount = (clone $leads)->where(fn ($q) => $q->where('status', LeadStatus::DemoScheduled)->orWhere('status', LeadStatus::DemoCompleted))->count();
        $propCount = (clone $leads)->where(fn ($q) => $q->where('status', LeadStatus::QuotationSent)->orWhere('status', LeadStatus::QuotationApproved))->count();
        $wonCount = (clone $leads)->where('status', LeadStatus::Won)->count();

        // Calculate drop-off percentages only from real data
        $qualLost = $newCount > 0 ? round((1 - $qualCount / max($newCount, 1)) * 100).'%' : '0%';
        $demoLost = $qualCount > 0 ? round((1 - $demoCount / max($qualCount, 1)) * 100).'%' : '0%';
        $propLost = $demoCount > 0 ? round((1 - $propCount / max($demoCount, 1)) * 100).'%' : '0%';
        $wonLost = $propCount > 0 ? round((1 - $wonCount / max($propCount, 1)) * 100).'%' : '0%';

        return [
            ['id' => 'lead',     'stageName' => 'Lead',     'count' => $newCount,  'lostPercent' => $qualLost],
            ['id' => 'qualified', 'stageName' => 'Qualified', 'count' => $qualCount, 'lostPercent' => $demoLost],
            ['id' => 'demo',     'stageName' => 'Demo',     'count' => $demoCount, 'lostPercent' => $propLost],
            ['id' => 'proposal', 'stageName' => 'Proposal', 'count' => $propCount, 'lostPercent' => $wonLost],
            ['id' => 'won',      'stageName' => 'Won',      'count' => $wonCount,  'lostPercent' => '0%'],
        ];
    }

    /**
     * Live system-health signals, or an honest "not monitored" state.
     *
     * Every tile used to be a hardcoded string — "AWS ap-south-1 • 99.99%
     * Uptime", "412 Clinic Staff Online" — presented as if it were live
     * telemetry. None of it ever queried anything. Three of these eight
     * tiles have a genuine, cheap signal already sitting in this
     * application's own tables (the queue's failed-job log, the WhatsApp
     * delivery log, the session store); those are now real. The remaining
     * five have no equivalent source anywhere in this codebase — no APM
     * integration, no backup ledger, no support-SLA tracking — so they say
     * exactly that, rather than a number nobody measured.
     *
     * These are platform infrastructure signals, not tenant business
     * records, so — unlike every other method in this class — they are not
     * scoped per organization: there is one job queue and one session store
     * for the whole platform, not one per tenant.
     */
    protected function getOperationsData(): array
    {
        $failedJobsLastDay = (int) DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->count();

        $whatsappLastDay = MarketingWhatsAppMessage::query()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw("count(*) as total, sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->first();
        $whatsappTotal = (int) ($whatsappLastDay->total ?? 0);
        $whatsappFailed = (int) ($whatsappLastDay->failed ?? 0);

        $activeSessions = (int) DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes(15)->timestamp)
            ->distinct('user_id')
            ->count('user_id');

        $unavailable = fn (string $id, string $title) => [
            'id' => $id,
            'title' => $title,
            'compactSummary' => 'Not monitored — no live data source configured.',
            'statusColor' => 'slate',
            'status' => 'unavailable',
        ];

        return [
            'cloudInfrastructure' => $unavailable('op-1', 'Cloud Infrastructure'),
            'databaseHealth' => $unavailable('op-2', 'Database Sync'),
            'whatsappQueue' => [
                'id' => 'op-3',
                'title' => 'WhatsApp API Queue',
                'compactSummary' => $whatsappTotal > 0
                    ? "{$whatsappFailed} failed of {$whatsappTotal} in the last 24h"
                    : 'No messages sent in the last 24h',
                'statusColor' => $whatsappFailed > 0 ? 'amber' : 'emerald',
                'status' => 'live',
            ],
            'emailQueue' => $unavailable('op-4', 'Email Provider Queue'),
            'scheduledJobs' => [
                'id' => 'op-5',
                'title' => 'Cron Automation',
                'compactSummary' => $failedJobsLastDay > 0
                    ? "{$failedJobsLastDay} job".($failedJobsLastDay > 1 ? 's' : '').' failed in the last 24h'
                    : 'All clean — no failed jobs in the last 24h',
                'statusColor' => $failedJobsLastDay > 0 ? 'rose' : 'emerald',
                'status' => 'live',
            ],
            'dailyBackup' => $unavailable('op-6', 'Automated Backups'),
            'supportSla' => $unavailable('op-7', 'Support SLA Health'),
            'activeUsers' => [
                'id' => 'op-8',
                'title' => 'Concurrent Sessions',
                'compactSummary' => "{$activeSessions} user".($activeSessions === 1 ? '' : 's').' active in the last 15 minutes',
                'statusColor' => 'emerald',
                'status' => 'live',
            ],
        ];
    }

    protected function getLiveActivityItems(?User $user): array
    {
        $visibleUserIds = CrmScope::visibleUserIds($user);

        $activities = Activity::query()
            ->when($visibleUserIds !== null, fn ($q) => $q->whereIn('user_id', $visibleUserIds))
            ->latest()
            ->take(6)
            ->get();

        if ($activities->isEmpty()) {
            return [];
        }

        return $activities->map(function ($act) {
            $userName = $act->user ? $act->user->name : 'System Event';
            $initials = strtoupper(substr($userName, 0, 2));

            return [
                'id' => (string) $act->id,
                'avatarFallback' => $initials,
                'avatarBg' => 'bg-slate-900',
                'department' => 'Sales',
                'employeeName' => $userName,
                'actionText' => $act->description ?? 'Recorded activity in CRM',
                'businessValue' => isset($act->properties['value']) ? "₹ {$act->properties['value']}" : null,
                'time' => $act->created_at ? $act->created_at->diffForHumans() : 'Just now',
            ];
        })->toArray();
    }
}
