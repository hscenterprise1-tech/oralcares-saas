<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Support\ReceptionFixture;
use Tighten\Ziggy\BladeRouteGenerator;

/**
 * Production addresses a clinic as `{clinic}.example.com` (domain mode), the
 * suite otherwise runs in path mode. These tests load the routes the way
 * domain mode does and walk the portal: every page renders, nothing links to
 * `/clinic/...`, the browser's route table carries the clinic, and a write
 * still binds its models with the host segment set aside.
 */
beforeEach(function () {
    config(['app.routing_mode' => 'domain', 'app.url' => 'http://oralcares.test']);
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    Route::middleware('web')->group(base_path('routes/web.php'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();

    $this->f = ReceptionFixture::make('smile');
    $this->host = 'http://smile.oralcares.test';
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
});

afterEach(function () {
    URL::defaults([]);
});

test('every portal page renders on the clinic host with no path-mode link in it', function (string $path) {
    $response = ($this->asOwner)()->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
        ->get($this->host.$path);

    $response->assertOk();
    expect(json_encode($response->json('props')))->not->toContain('\/clinic\/');
})->with(['/dashboard', '/reception', '/patients', '/appointments', '/billing', '/payroll', '/expenses', '/approvals', '/admin/settings', '/admin/consents', '/reports', '/visits']);

test('the browser route table carries the clinic, so route() builds this host\'s links', function () {
    // Ziggy prints its table once per process; earlier pages in this worker must not hide it.
    BladeRouteGenerator::$generated = false;
    $html = ($this->asOwner)()->get($this->host.'/reception')->assertOk()->getContent();

    expect($html)->toMatch('/"defaults":\{[^}]*"clinic":"smile"/');
});

test('a write binds its models with the host segment set aside', function () {
    $patient = $this->f->patient();

    ($this->asOwner)()->postJson($this->host.'/reception/check-in', ['patient_id' => $patient->id, 'practitioner_id' => $this->f->doctorA->id])
        ->assertCreated();
    ($this->asOwner)()->getJson($this->host.'/patients/'.$patient->id.'/360/timeline')->assertOk();
});

test('a clinic\'s host never serves another clinic\'s member', function () {
    $other = ReceptionFixture::make('elsewhere');

    $this->actingAs($other->owner)->withSession($other->session)->get($this->host.'/reception')->assertForbidden();
});
