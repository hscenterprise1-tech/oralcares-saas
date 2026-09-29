<?php

use App\Models\CompanyEmployee;
use App\Models\CompanySupportSlaTarget;
use App\Models\CompanySupportTicket;
use App\Models\Organization;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use App\SupportTicketPriority;
use App\SupportTicketStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Customer Operations — support, reporting, audit and notification ops.
 *
 * What these pin, mostly, is restraint. No SLA interval is invented, no
 * accounting metric is derived that the data cannot support, the audit trail
 * has no write path at all, and the notification module is read rather than
 * rebuilt.
 */
function opsUser(array $permissions = []): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

function ticketFor(array $overrides = []): CompanySupportTicket
{
    return app(SupportTicketService::class)->open(array_merge([
        'subject' => 'Cannot print an invoice',
        'body' => 'The button does nothing.',
        'priority' => SupportTicketPriority::Normal->value,
    ], $overrides));
}

beforeEach(function () {
    setPermissionsTeamId(0);

    foreach (['view-support', 'manage-support', 'view-reports', 'view-audit-logs', 'view-notifications'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $this->agent = opsUser(['view-support', 'manage-support']);
    $this->reader = opsUser(['view-support', 'view-reports', 'view-audit-logs', 'view-notifications']);
});

/* ═══════════════════════════ super admin bypass ═════════════════════════ */

it('lets the super admin open every operations surface holding nothing', function () {
    expect($this->admin->getAllPermissions())->toBeEmpty();

    $ticket = ticketFor();

    foreach ([
        route('company.support.index'),
        route('company.support.show', $ticket),
        route('company.reports.index'),
        route('company.audit.index'),
        route('company.notifications.ops'),
    ] as $url) {
        $this->actingAs($this->admin)->get($url)->assertSuccessful();
    }
});

/* ═════════════════════ support: read / write split ══════════════════════ */

it('refuses a reader the right to open or change a ticket', function () {
    $ticket = ticketFor();

    $this->actingAs($this->reader)->get(route('company.support.index'))->assertOk();

    $this->actingAs($this->reader)
        ->post(route('company.support.store'), ['subject' => 'Sneaky', 'priority' => 'normal'])
        ->assertForbidden();

    $this->actingAs($this->reader)
        ->post(route('company.support.update', $ticket), ['status' => 'resolved'])
        ->assertForbidden();

    expect(CompanySupportTicket::count())->toBe(1)
        ->and($ticket->fresh()->status)->toBe(SupportTicketStatus::Open);
});

it('refuses every operations surface to a user holding nothing', function () {
    $nobody = opsUser();

    foreach ([
        route('company.support.index'),
        route('company.reports.index'),
        route('company.audit.index'),
        route('company.notifications.ops'),
    ] as $url) {
        $this->actingAs($nobody)->get($url)->assertForbidden();
    }
});

/* ═══════════════════════════ support lifecycle ══════════════════════════ */

it('opens a ticket against a customer account with a history from the first moment', function () {
    $organization = Organization::factory()->create(['name' => 'Northgate Dental']);

    $this->actingAs($this->agent)
        ->post(route('company.support.store'), [
            'subject' => 'Cannot log in',
            'body' => 'Password reset never arrives.',
            'organization_id' => $organization->id,
            'priority' => SupportTicketPriority::High->value,
        ])
        ->assertRedirect();

    $ticket = CompanySupportTicket::sole();

    expect($ticket->status)->toBe(SupportTicketStatus::Open)
        ->and($ticket->priority)->toBe(SupportTicketPriority::High)
        ->and($ticket->organization_id)->toBe($organization->id)
        ->and($ticket->reference)->toStartWith('SUP-')
        ->and($ticket->events)->toHaveCount(1)
        ->and($ticket->events->first()->event_type)->toBe('opened');
});

it('walks a ticket through its states and records every change', function () {
    $ticket = ticketFor();
    $agentEmployee = CompanyEmployee::create([
        'user_id' => $this->agent->id,
        'employee_code' => 'EMP-9001',
        'employment_status' => 'active',
    ]);

    $this->actingAs($this->agent)
        ->post(route('company.support.update', $ticket), [
            'priority' => SupportTicketPriority::Urgent->value,
            'assigned_to' => $agentEmployee->id,
            'status' => SupportTicketStatus::Resolved->value,
            'note' => 'Cleared the cache.',
        ])
        ->assertRedirect();

    $ticket = $ticket->fresh(['events']);

    expect($ticket->status)->toBe(SupportTicketStatus::Resolved)
        ->and($ticket->priority)->toBe(SupportTicketPriority::Urgent)
        ->and($ticket->assigned_to)->toBe($agentEmployee->id)
        ->and($ticket->resolved_at)->not->toBeNull()
        ->and($ticket->resolution_note)->toBe('Cleared the cache.')
        /* opened + priority + assignment + status */
        ->and($ticket->events)->toHaveCount(4);
});

it('refuses a transition the status does not allow', function () {
    $ticket = ticketFor();
    app(SupportTicketService::class)->transition($ticket, SupportTicketStatus::Closed);

    $this->actingAs($this->agent)
        ->post(route('company.support.update', $ticket->fresh()), ['status' => SupportTicketStatus::Resolved->value])
        ->assertSessionHasErrors('status');

    expect($ticket->fresh()->status)->toBe(SupportTicketStatus::Closed);
});

it('stops the first-response clock on a reply but never on an internal note', function () {
    $ticket = ticketFor();

    $this->actingAs($this->agent)
        ->post(route('company.support.comment', $ticket), ['body' => 'Looking into it.', 'is_internal' => true])
        ->assertRedirect();

    expect($ticket->fresh()->first_responded_at)->toBeNull();

    $this->actingAs($this->agent)
        ->post(route('company.support.comment', $ticket), ['body' => 'We have fixed this.', 'is_internal' => false])
        ->assertRedirect();

    expect($ticket->fresh()->first_responded_at)->not->toBeNull();
});

/* ══════════════════════════ SLA: nothing invented ═══════════════════════ */

it('gives a ticket no due date until somebody sets a target', function () {
    /*
     * How long a customer should wait is a commitment the business makes, not
     * a constant this codebase gets to choose.
     */
    expect(CompanySupportSlaTarget::count())->toBe(0);

    $ticket = ticketFor();

    expect($ticket->first_response_due_at)->toBeNull()
        ->and($ticket->resolution_due_at)->toBeNull()
        /* Null, not false — "no target" is not "on time". */
        ->and($ticket->breachedFirstResponse())->toBeNull()
        ->and($ticket->breachedResolution())->toBeNull();
});

it('clocks a ticket once a target is configured', function () {
    $this->actingAs($this->agent)
        ->post(route('company.support.sla.update'), [
            'targets' => [
                ['priority' => 'urgent', 'first_response_minutes' => 30, 'resolution_minutes' => 240],
            ],
        ])
        ->assertRedirect();

    $ticket = ticketFor(['priority' => SupportTicketPriority::Urgent->value]);

    expect($ticket->first_response_due_at)->not->toBeNull()
        ->and($ticket->resolution_due_at)->not->toBeNull()
        ->and($ticket->breachedFirstResponse())->toBeFalse();
});

it('does not re-clock a first response that has already been given', function () {
    CompanySupportSlaTarget::create(['priority' => 'normal', 'first_response_minutes' => 60]);
    CompanySupportSlaTarget::create(['priority' => 'urgent', 'first_response_minutes' => 15]);

    $ticket = ticketFor();
    $service = app(SupportTicketService::class);
    $service->comment($ticket, 'Replying now.', false);

    $due = $ticket->fresh()->first_response_due_at;

    $service->reprioritise($ticket->fresh(), SupportTicketPriority::Urgent);

    expect($ticket->fresh()->first_response_due_at?->toDateTimeString())->toBe($due?->toDateTimeString());
});

/* ═══════════════════════ support is not a CRM task ══════════════════════ */

it('writes no CRM task when a ticket is opened', function () {
    $before = DB::table('tasks')->count();

    ticketFor();

    expect(DB::table('tasks')->count())->toBe($before)
        ->and(CompanySupportTicket::count())->toBe(1);
});

/* ═══════════════════════════════ reports ════════════════════════════════ */

it('builds a report from the modules that own each figure', function () {
    $this->actingAs($this->reader)
        ->get(route('company.reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Operations/ReportsWorkspace')
            ->has('report.revenue')
            ->has('report.pipeline')
            ->has('report.customers')
            ->has('report.spend')
            ->has('report.people')
            ->has('report.support')
            ->has('report.window')
        );
});

it('reports a figure it cannot compute as unavailable rather than zero', function () {
    /*
     * No MRR is recorded on any subscription, and nothing has been resolved,
     * so both come back null — and the export says so in words.
     */
    $this->actingAs($this->reader)
        ->get(route('company.reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('report.revenue.mrr_now', null)
            ->where('report.support.median_resolution_hours', null)
            ->where('report.support.sla_breaches', null)
        );

    $csv = $this->actingAs($this->reader)->get(route('company.reports.export'))->streamedContent();

    expect($csv)->toContain('not available')
        ->and($csv)->toContain('Domain,Metric,Value');
});

it('invents no accounting metric the data cannot support', function () {
    /* No cost of revenue exists anywhere, so no margin, burn or runway. */
    $source = File::get(app_path('Services/Reporting/CompanyReportService.php'));

    foreach (['margin', 'burn_rate', 'runway', 'ebitda', 'profit'] as $forbidden) {
        expect(str_contains(strtolower($source), "'{$forbidden}'"))->toBeFalse();
    }
});

it('defaults the reporting window to this month rather than all time', function () {
    $this->actingAs($this->reader)
        ->get(route('company.reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', now()->startOfMonth()->toDateString())
            ->where('filters.to', now()->endOfMonth()->toDateString())
        );
});

/* ════════════════════════════════ audit ════════════════════════════════ */

it('surfaces the existing audit sources without creating another one', function () {
    DB::table('activities')->insert([
        'subject_type' => 'lead', 'subject_id' => 1, 'user_id' => $this->admin->id,
        'type' => 'system_update', 'description' => 'Deal moved to Won.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->reader)
        ->get(route('company.audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Operations/AuditWorkspace')
            ->has('entries', 1)
            ->where('totals.activities', 1)
        );
});

it('offers no way to write, edit or delete an audit entry', function () {
    /* A history that can be corrected is not a history. */
    $routes = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->uri(), 'company/audit'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->values();

    expect($routes->all())->toBe(['GET', 'HEAD']);

    $source = File::get(app_path('Http/Controllers/Company/Operations/AuditController.php'));

    foreach (['->update(', '->delete(', '->create(', '->save('] as $mutation) {
        expect(str_contains($source, $mutation))->toBeFalse();
    }
});

/* ════════════════════════════ notifications ════════════════════════════ */

it('reads the existing notification module rather than duplicating it', function () {
    $this->actingAs($this->reader)
        ->get(route('company.notifications.ops'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Operations/NotificationsWorkspace')
            ->has('summary.total')
            ->has('summary.failed')
            ->where('inboxUrl', '/notifications')
        );

    /* No parallel model, table or channel was introduced. */
    expect(File::exists(app_path('Models/CompanyNotification.php')))->toBeFalse()
        ->and(File::exists(app_path('Services/Notifications')))->toBeFalse();

    $source = File::get(app_path('Http/Controllers/Company/Operations/NotificationOpsController.php'));

    expect($source)->toContain('App\\Shared\\Notification\\Models\\EnterpriseNotification');
});

it('offers no way to alter a delivery record', function () {
    $routes = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->uri(), 'company/notifications-ops'))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->values();

    expect($routes->all())->toBe(['GET', 'HEAD']);
});
