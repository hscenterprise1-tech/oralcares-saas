<?php

use App\ClinicRole;
use App\Models\CompanySupportTicket;
use App\Services\Support\SupportTicketService;
use App\SupportTicketStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * The navigation's Support and Notifications destinations: real pages on the
 * support desk's tickets and the shared notification feed, bound to the
 * clinic of the session. A clinic never sees another clinic's requests, a
 * member sees their own unless they run the clinic, and the desk's internal
 * notes never reach the clinic.
 */
beforeEach(function () {
    $this->f = ReceptionFixture::make('support-clinic');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->desk = app(SupportTicketService::class);
});

test('a member raises a request that lands on the support desk for this clinic', function () {
    [$user, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $response = $this->actingAs($user)->withSession($session)->post('/clinic/support', [
        'subject' => 'Calendar does not show Friday',
        'body' => 'The week view stops at Thursday for our branch.',
    ]);

    $ticket = CompanySupportTicket::query()->sole();
    $response->assertRedirect(route('clinic.support.show', ['ticket' => $ticket->id]));
    expect($ticket->clinic_id)->toBe($this->f->clinic->id)
        ->and($ticket->organization_id)->toBe($this->f->clinic->organization_id)
        ->and($ticket->channel)->toBe('portal')
        ->and($ticket->opened_by)->toBe($user->id)
        ->and($ticket->reporter_email)->toBe($user->email)
        ->and($ticket->status)->toBe(SupportTicketStatus::Open)
        ->and($ticket->events()->pluck('event_type')->all())->toBe(['opened']);
});

test('a request needs a subject and a description', function () {
    ($this->asOwner)()->post('/clinic/support', ['subject' => '', 'body' => ''])
        ->assertSessionHasErrors(['subject', 'body']);

    expect(CompanySupportTicket::query()->count())->toBe(0);
});

test('a member sees their own requests, the owner sees the clinic\'s, and nobody sees another clinic\'s', function () {
    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $mine = $this->desk->open(['clinic_id' => $this->f->clinic->id, 'subject' => 'Mine'], $reception);
    $colleague = $this->desk->open(['clinic_id' => $this->f->clinic->id, 'subject' => 'The owner\'s'], $this->f->owner);
    $other = ReceptionFixture::make('other-support-clinic');
    $foreign = $this->desk->open(['clinic_id' => $other->clinic->id, 'subject' => 'Another clinic'], $other->owner);

    $this->actingAs($reception)->withSession($session)->get('/clinic/support')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Support/Index', true)
            ->where('scope', 'mine')
            ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$mine->id]));
    $this->actingAs($reception)->withSession($session)->get("/clinic/support/{$colleague->id}")->assertNotFound();

    ($this->asOwner)()->get('/clinic/support')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('scope', 'clinic')
            ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$mine->id, $colleague->id])->sort()->values()->all()));
    ($this->asOwner)()->get("/clinic/support/{$foreign->id}")->assertNotFound();
});

test('the history shows the desk\'s replies and status changes, never its internal notes', function () {
    $ticket = $this->desk->open(['clinic_id' => $this->f->clinic->id, 'subject' => 'Printing receipts', 'body' => 'Receipts print blank.'], $this->f->owner);
    $this->desk->comment($ticket, 'Internal: check the printer driver.', true);
    $this->desk->comment($ticket, 'Could you send a photo of the receipt?', false);
    $this->desk->transition($ticket->fresh(), SupportTicketStatus::Pending, null, 'Internal: waiting on them.');

    ($this->asOwner)()->get("/clinic/support/{$ticket->id}")->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('ClinicPortal/Support/Show', true)->where('ticket.status.value', 'pending');
            $history = collect($page->toArray()['props']['history']);

            expect($history->pluck('kind')->all())->toBe(['opened', 'reply', 'status'])
                ->and($history->firstWhere('kind', 'reply')['body'])->toBe('Could you send a photo of the receipt?')
                ->and($history->firstWhere('kind', 'status')['body'])->toBeNull()
                ->and($history->firstWhere('kind', 'status')['status']['value'])->toBe('pending')
                ->and(json_encode($history->all()))->not->toContain('Internal:');
        });
});

test('Notifications and Support are working sidebar destinations for every member, and Daily Closing is gone', function () {
    [$reception, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $this->actingAs($reception)->withSession($session)->get('/clinic/notifications')->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('ClinicPortal/Notifications', true)->has('links.read');
            $navigation = collect($page->toArray()['props']['clinic']['navigation'])->keyBy('key');

            expect($navigation['notifications']['available'])->toBeTrue()
                ->and($navigation['notifications']['href'])->toEndWith('/clinic/notifications')
                ->and($navigation['support']['available'])->toBeTrue()
                ->and($navigation->keys()->all())->not->toContain('daily_closing');
        });
});
