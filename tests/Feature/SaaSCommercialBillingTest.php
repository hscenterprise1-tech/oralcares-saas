<?php

use App\Events\CommercialInvoiceGenerated;
use App\Events\CommercialInvoicePaid;
use App\Events\SubscriptionActivated;
use App\Events\SubscriptionExpired;
use App\Events\SubscriptionRenewed;
use App\Models\Clinic;
use App\Models\CommercialInvoice;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Services\CommercialInvoiceService;
use App\Services\RenewalService;
use App\Services\SubscriptionPlanService;
use App\Services\SubscriptionService;
use App\Services\UsageMetricService;
use App\SubscriptionStatus;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('subscription plans can be created and versioned with features', function () {
    $planService = app(SubscriptionPlanService::class);

    // Create a base features catalog item
    $feature = CompanyFeature::create([
        'name' => 'Advanced Analytics',
        'key' => 'advanced_analytics',
        'description' => 'Enables custom analytics tables.',
    ]);

    // 1. Create a plan
    $plan = $planService->createPlan([
        'name' => 'Professional Plan',
        'key' => 'professional',
        'description' => 'Pro clinical tools package',
        'price' => 199.00,
        'billing_period' => 'monthly',
        'version' => '1.0',
        'max_users' => 10,
        'max_doctors' => 5,
        'max_branches' => 2,
        'storage_limit_gb' => 50,
        'is_active' => true,
    ], ['advanced_analytics']);

    expect($plan->version)->toBe('1.0')
        ->and($plan->features)->toHaveCount(1)
        ->and($plan->features->first()->key)->toBe('advanced_analytics');

    // 2. Version the plan to 2.0
    $v2Plan = $planService->versionPlan($plan, '2.0', ['price' => 249.00, 'deactivate_old' => true]);

    expect($v2Plan->version)->toBe('2.0')
        ->and($v2Plan->price)->toEqual(249.00)
        ->and($plan->fresh()->is_active)->toBeFalse();
});

test('subscriptions lifecycle status transitions and grace period verification', function () {
    Event::fake([
        SubscriptionActivated::class,
        SubscriptionExpired::class,
        SubscriptionRenewed::class,
    ]);

    $clinic = Clinic::factory()->create();
    $subService = app(SubscriptionService::class);

    $subscription = CompanySubscription::create([
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Pending,
        'starts_at' => now(),
        'ends_at' => now()->addYear(),
        'max_users' => 5,
        'max_doctors' => 3,
        'max_branches' => 1,
        'storage_limit_gb' => 10,
    ]);

    // 1. Activate
    $subService->activateSubscription($subscription);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    Event::assertDispatched(SubscriptionActivated::class);

    // 2. Renew
    $subService->renewSubscription($subscription, 12);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->fresh()->ends_at->year)->toBe(now()->year + 2);
    Event::assertDispatched(SubscriptionRenewed::class);

    // 3. Grace Period Check
    // Active subscription is not under grace period
    expect($subService->isUnderGracePeriod($subscription))->toBeFalse();

    // Make subscription expired within 5 days ago
    $subscription->update([
        'status' => SubscriptionStatus::Expired,
        'ends_at' => now()->subDays(5),
    ]);

    expect($subService->isUnderGracePeriod($subscription))->toBeTrue();
});

test('commercial invoices generation calculates correct totals with tax', function () {
    Event::fake([CommercialInvoiceGenerated::class]);

    $clinic = Clinic::factory()->create();
    $plan = CompanyPlan::create([
        'name' => 'Starter Plan',
        'key' => 'starter',
        'price' => 100.00,
        'billing_period' => 'monthly',
        'version' => '1.0',
    ]);

    $subscription = CompanySubscription::create([
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
        'max_users' => 5,
        'max_doctors' => 3,
        'max_branches' => 1,
        'storage_limit_gb' => 10,
    ]);

    $invoiceService = app(CommercialInvoiceService::class);
    $invoice = $invoiceService->generateInvoice($subscription);

    // Standard subtotal = 100.00, 18% tax = 18.00, discount = 0.00, final = 118.00
    expect($invoice->amount)->toEqual(100.00)
        ->and($invoice->tax_amount)->toEqual(18.00)
        ->and($invoice->final_amount)->toEqual(118.00)
        ->and($invoice->invoice_number)->toStartWith('INV-SaaS-')
        ->and($invoice->status)->toBe('draft');

    Event::assertDispatched(CommercialInvoiceGenerated::class);
});

test('commercial payments, refunds, and credit notes recording transitions status', function () {
    Event::fake([CommercialInvoicePaid::class]);

    $clinic = Clinic::factory()->create();
    $invoice = CommercialInvoice::create([
        'clinic_id' => $clinic->id,
        'invoice_number' => 'INV-SaaS-MOCKPAY',
        'status' => 'issued',
        'amount' => 100.00,
        'tax_amount' => 18.00,
        'discount_amount' => 0.00,
        'final_amount' => 118.00,
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $invoiceService = app(CommercialInvoiceService::class);

    // Record partial payment
    $invoiceService->recordPayment($invoice, 50.00, 'credit_card', 'TXN-111');
    expect($invoice->fresh()->status)->toBe('issued'); // Not fully paid yet

    // Record remaining payment
    $invoiceService->recordPayment($invoice, 68.00, 'credit_card', 'TXN-222');
    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->paid_at)->not->toBeNull();

    Event::assertDispatched(CommercialInvoicePaid::class);

    // Record refund
    $refund = $invoiceService->recordRefund($invoice, 20.00, 'REF-111');
    expect($refund->payment_type)->toBe('refund')
        ->and($refund->amount)->toEqual(20.00);

    // Record credit note
    $credit = $invoiceService->recordCreditNote($invoice, 10.00, 'CN-111');
    expect($credit->payment_type)->toBe('credit_note')
        ->and($credit->amount)->toEqual(10.00);
});

test('usage metrics logging and range sum aggregation works correctly', function () {
    $clinic = Clinic::factory()->create();
    $usageService = app(UsageMetricService::class);

    // Log branch usage for 3 days
    $usageService->recordMetric($clinic, 'active_branches', 2.00, '2026-07-15');
    $usageService->recordMetric($clinic, 'active_branches', 3.00, '2026-07-16');
    $usageService->recordMetric($clinic, 'active_branches', 4.00, '2026-07-17');

    // Get current latest usage
    expect($usageService->getCurrentUsage($clinic, 'active_branches'))->toEqual(4.00);

    // Get sum aggregation for the period
    $sum = $usageService->getMetricSum($clinic, 'active_branches', '2026-07-15', '2026-07-16');
    expect($sum)->toEqual(5.00);
});

test('renewal engine automatically expires out-of-date accounts and generates invoices', function () {
    $clinic = Clinic::factory()->create();
    $plan = CompanyPlan::create([
        'name' => 'Starter Plan',
        'key' => 'starter',
        'price' => 100.00,
        'billing_period' => 'monthly',
        'version' => '1.0',
    ]);

    // Sub 1: Past ends_at (expiring today)
    $expiredSub = CompanySubscription::create([
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDays(2),
        'max_users' => 5,
        'max_doctors' => 3,
        'max_branches' => 1,
        'storage_limit_gb' => 10,
    ]);

    // Sub 2: Expiring in 10 days (requires renewal invoice generation)
    $upcomingSub = CompanySubscription::create([
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDays(20),
        'ends_at' => now()->addDays(10),
        'max_users' => 5,
        'max_doctors' => 3,
        'max_branches' => 1,
        'storage_limit_gb' => 10,
    ]);

    $renewalService = app(RenewalService::class);
    $result = $renewalService->checkAndProcessRenewals();

    expect($result['expired_count'])->toBe(1)
        ->and($result['invoiced_count'])->toBe(1)
        ->and($expiredSub->fresh()->status)->toBe(SubscriptionStatus::Expired)
        // Check that a commercial invoice was generated for upcoming sub
        ->and(CommercialInvoice::where('company_subscription_id', $upcomingSub->id)->exists())->toBeTrue();
});
