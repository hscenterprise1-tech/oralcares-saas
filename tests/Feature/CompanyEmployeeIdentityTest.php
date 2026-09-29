<?php

use App\EmploymentStatus;
use App\Mail\CompanyEmployeeInvitationMail;
use App\Models\CompanyEmployee;
use App\Models\CompanyEmployeeInvitation;
use App\Models\User;
use App\Services\CompanyEmployeeInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

test('invitation can be created and email is sent', function () {
    $admin = User::factory()->masterAdmin()->create();
    $user = User::factory()->companyUser()->create();
    $employee = CompanyEmployee::factory()->create([
        'user_id' => $user->id,
        'employment_status' => EmploymentStatus::PendingInvitation,
    ]);

    $service = app(CompanyEmployeeInvitationService::class);
    $invitation = $service->generateInvitation($employee, $user->email, $admin);

    expect($invitation)->not->toBeNull()
        ->and($invitation->company_employee_id)->toBe($employee->id)
        ->and($invitation->email)->toBe($user->email)
        ->and($invitation->isValid())->toBeTrue();

    expect($employee->fresh()->employment_status)->toBe(EmploymentStatus::Invited);

    Mail::assertSent(CompanyEmployeeInvitationMail::class);

    expect($employee->activities)->toHaveCount(2)
        ->and($employee->activities->pluck('event'))->toContain('Invitation Created', 'Invitation Sent');
});

test('valid invitation token can be validated', function () {
    $invitation = CompanyEmployeeInvitation::factory()->create();
    $service = app(CompanyEmployeeInvitationService::class);

    $validated = $service->validateToken($invitation->token);
    expect($validated->id)->toBe($invitation->id);
});

test('expired invitation validation throws exception', function () {
    $invitation = CompanyEmployeeInvitation::factory()->expired()->create();
    $service = app(CompanyEmployeeInvitationService::class);

    $this->expectException(LogicException::class);
    $service->validateToken($invitation->token);
});

test('revoked invitation validation throws exception', function () {
    $invitation = CompanyEmployeeInvitation::factory()->revoked()->create();
    $service = app(CompanyEmployeeInvitationService::class);

    $this->expectException(LogicException::class);
    $service->validateToken($invitation->token);
});

test('employee sets password accepts invitation and becomes active', function () {
    $user = User::factory()->companyUser()->create([
        'password' => Hash::make('old-password'),
        'email_verified_at' => null,
    ]);
    $employee = CompanyEmployee::factory()->create([
        'user_id' => $user->id,
        'employment_status' => EmploymentStatus::Invited,
    ]);
    $invitation = CompanyEmployeeInvitation::factory()->create([
        'company_employee_id' => $employee->id,
        'email' => $user->email,
    ]);

    $service = app(CompanyEmployeeInvitationService::class);
    $service->acceptInvitation($invitation->token, 'NewSecurePassword123!');

    expect($employee->fresh()->employment_status)->toBe(EmploymentStatus::Active)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('NewSecurePassword123!', $user->password))->toBeTrue();

    expect($employee->activities)->toHaveCount(2)
        ->and($employee->activities->pluck('event'))->toContain('Invitation Accepted', 'Password Created');
});

test('inactive or suspended employees cannot log in', function () {
    $user = User::factory()->companyUser()->create();
    $employee = CompanyEmployee::factory()->create([
        'user_id' => $user->id,
        'employment_status' => EmploymentStatus::Suspended,
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors(['email']);
    $this->assertGuest();
});

test('active employee can log in and logs first login activity', function () {
    $user = User::factory()->companyUser()->create();
    $employee = CompanyEmployee::factory()->create([
        'user_id' => $user->id,
        'employment_status' => EmploymentStatus::Active,
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('company.dashboard'));
    $this->assertAuthenticatedAs($user);

    expect($employee->activities()->where('event', 'First Login')->exists())->toBeTrue();

    $this->post('/logout');
    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    expect($employee->activities()->where('event', 'First Login')->count())->toBe(1);
});

test('master admin is unaffected by employee login restrictions', function () {
    $admin = User::factory()->masterAdmin()->create();

    $response = $this->post('/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('company.dashboard'));
    $this->assertAuthenticatedAs($admin);
});
