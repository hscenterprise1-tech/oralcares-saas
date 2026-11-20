<?php

namespace App\Http\Controllers\Clinic;

use App\BillingStatus;
use App\ClinicPermission;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\User;
use App\PaymentMethod;
use App\Services\Billing\BillingRegister;
use App\Services\Billing\BillInstalments;
use App\Services\Billing\CheckInCharges;
use App\Services\Billing\EncounterCheckout;
use App\Services\Billing\PaymentMessages;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Patient\Patient360\Patient360Sections;
use App\Services\PaymentService;
use App\Services\Physio\SessionPackages;
use App\Services\Reception\IntakeSubmissions;
use App\Services\Security\SecurityAudit;
use App\Support\BillingTabs;
use App\Support\ClinicAccess\BranchReach;
use App\Support\ClinicAccess\ClinicAccess;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Reception's side of the hand-off after the doctor: signed consultations
 * ready to bill, the checkout of one consultation (bill, payment, follow-up)
 * and bills still waiting for payment. Bills and payments are written only
 * by `BillingService` / `PaymentService`, through `EncounterCheckout`.
 *
 * Routes: `billing.view` to read; raising a bill needs `billing.create`, a
 * bill discount `discount.apply`, a payment `payment.collect`.
 */
class CheckoutController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected EncounterCheckout $checkout,
    ) {}

    /**
     * Bills & Collections: every bill (or every payment received), filtered
     * on the server and paged, with totals that are the sums of the same
     * rows. Scope is `BranchReach` for `billing.view`. Detail and actions come
     * from `bill()`, each action still authorized by its own route.
     */
    public function index(Request $request, BillingRegister $register, ClinicAccess $access): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->branchScopeOrAbort();
        $reach = $register->reach(ClinicPermission::BillingView);
        $filters = $register->filters($request);
        $view = $request->query('view') === 'collections' ? 'collections' : 'bills';
        $status = $filters['status'];
        $search = $filters['q'];
        $bills = $register->bills($clinic->id, $reach, $filters);
        $collections = $view === 'collections' ? $register->collections($clinic->id, $reach, $filters) : null;

        $unpaid = PatientBilling::query()->where('clinic_id', $clinic->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])
            ->where('outstanding_balance', '>', 0)
            ->with(['patient' => fn ($query) => $query->withoutGlobalScopes()])
            ->latest('issued_at')->limit(100)->get();

        return Inertia::render('ClinicPortal/Billing/Index', [
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'ready' => $this->checkout->readyToBill($clinic, $branch)->map(fn (ClinicalEncounter $encounter) => [
                'id' => $encounter->id,
                'number' => $encounter->encounter_number,
                'patient' => $encounter->patient?->short_name,
                'uhid' => $encounter->patient?->uhid,
                'doctor' => $encounter->doctor?->name,
                'signed_at' => $encounter->finalized_at?->toIso8601String(),
                'url' => route('clinic.billing.checkout', $encounter),
            ])->values(),
            'unpaid' => $unpaid->map(fn (PatientBilling $bill) => [
                'id' => $bill->id,
                'number' => $bill->billing_number,
                'patient' => $bill->patient?->short_name,
                'uhid' => $bill->patient?->uhid,
                'total' => (float) $bill->final_amount,
                'outstanding' => (float) $bill->outstanding_balance,
                'issued_at' => $bill->issued_at?->toIso8601String(),
                'url' => $bill->encounter_id ? route('clinic.billing.checkout', $bill->encounter_id) : route('clinic.patients.show', $bill->patient_id),
            ])->values(),
            'view' => $view,
            // Bills issued in range, newest first, with the patient and branch.
            'bills' => $view === 'bills' ? (clone $bills)
                ->leftJoin('patients', 'patients.id', '=', 'patient_billings.patient_id')
                ->leftJoin('branches', 'branches.id', '=', 'patient_billings.branch_id')
                ->orderByDesc('patient_billings.issued_at')->orderByDesc('patient_billings.id')
                ->select('patient_billings.*', 'patients.first_name', 'patients.last_name', 'patients.uhid', 'branches.name as branch_name')
                ->paginate(BillingRegister::PAGE)->withQueryString()
                ->through(fn ($bill) => [
                    'id' => $bill->id,
                    'number' => $bill->billing_number,
                    'patient' => trim($bill->first_name.' '.$bill->last_name),
                    'uhid' => $bill->uhid,
                    'branch' => $bill->branch_name,
                    'status' => $bill->status,
                    'total' => (float) $bill->final_amount,
                    'paid' => (float) $bill->paid_amount,
                    'refunded' => (float) $bill->refunded_amount,
                    'adjusted' => (float) $bill->adjusted_amount,
                    'outstanding' => (float) $bill->outstanding_balance,
                    'issued_at' => $bill->issued_at ? CarbonImmutable::parse($bill->issued_at, 'UTC')->toIso8601String() : null,
                ]) : null,
            'totals' => $view === 'bills' ? $register->billTotals($bills) : [
                'count' => (clone $collections)->count(),
                'collected' => round((float) (clone $collections)->sum('patient_billing_payments.amount'), 2),
            ],
            // Payments received in range: the receipts.
            'collections' => $collections === null ? null : (clone $collections)
                ->leftJoin('patients', 'patients.id', '=', 'patient_billings.patient_id')
                ->leftJoin('branches', 'branches.id', '=', 'patient_billing_payments.branch_id')
                ->orderByDesc('patient_billing_payments.paid_at')->orderByDesc('patient_billing_payments.id')
                ->select('patient_billing_payments.*', 'patient_billings.billing_number', 'patients.first_name', 'patients.last_name', 'patients.uhid', 'branches.name as branch_name')
                ->paginate(BillingRegister::PAGE)->withQueryString()
                ->through(fn ($payment) => [
                    'id' => $payment->id,
                    'bill_id' => $payment->patient_billing_id,
                    'bill_number' => $payment->billing_number,
                    'receipt' => $payment->receipt_number,
                    'patient' => trim($payment->first_name.' '.$payment->last_name),
                    'uhid' => $payment->uhid,
                    'branch' => $payment->branch_name,
                    'method' => $payment->payment_method,
                    'reference' => $payment->transaction_reference,
                    'amount' => (float) $payment->amount,
                    'paid_at' => $payment->paid_at ? CarbonImmutable::parse($payment->paid_at, 'UTC')->toIso8601String() : null,
                ]),
            'filters' => $filters,
            'statuses' => collect(BillingStatus::cases())->map(fn (BillingStatus $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            'methods' => app(PaymentService::class)->methodOptions($clinic),
            'branches' => $this->branchOptions($clinic->id, $reach),
            'tabs' => BillingTabs::for($access, $this->routeParameters($request)),
            'links' => ['bill' => route('clinic.billing.bills.show', ['bill' => '__ID__'], false)],
            'can' => ['adjust' => $request->user()->hasClinicPermission(ClinicPermission::DiscountApply)],
        ]);
    }

    /**
     * One bill in full, for the Billing pages' detail view: lines, payments,
     * refunds and the actions this member may take on it, each decided at the
     * bill's own branch (`Patient360Sections::bill()`, the same read Patient
     * 360 uses). The actions are still authorized again by their routes.
     */
    public function bill(Request $request, int $bill): JsonResponse
    {
        $record = PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($bill);
        // As in the list: only at a branch where this member holds billing.
        $this->assertBranchId($record->branch_id, ClinicPermission::BillingView);
        $record->load(['patient' => fn ($query) => $query->withoutGlobalScopes()]);

        return response()->json([
            'patient' => $record->patient ? ['name' => $record->patient->full_name, 'uhid' => $record->patient->uhid] : null,
            ...app(Patient360Sections::class)->bill($record, $request->user()),
        ]);
    }

    /**
     * The branches a Billing filter may name: only those inside the reach.
     *
     * @return list<array{value: string, label: string}>
     */
    private function branchOptions(int $clinicId, BranchReach $reach): array
    {
        return Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Branch $branch) => $reach->covers($branch->id))
            ->map(fn (Branch $branch) => ['value' => (string) $branch->id, 'label' => $branch->name])->values()->all();
    }

    /** @return array<string, string> */
    private function routeParameters(Request $request): array
    {
        return array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value));
    }

    /**
     * A concession or write-off on what is still due; the bill itself is not
     * edited. A request key made when the form opened records it once however
     * often it is sent (the refund's pattern): a refused adjustment frees the
     * key for the corrected retry, and the key belongs to this clinic, user
     * and bill. Without a key it still runs, and is logged.
     */
    public function adjust(Request $request, PatientBilling $bill, IntakeSubmissions $submissions): RedirectResponse
    {
        abort_unless($bill->clinic_id === $this->clinicContext->clinic()?->id, 404);
        $this->assertBranchId($bill->branch_id, ClinicPermission::DiscountApply);
        app(OwnerMode::class)->requireForSensitiveAction($request->user(), 'billing.adjust', OwnerMode::STANDARD, ['bill_id' => $bill->id, 'branch_id' => $bill->branch_id]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ]);
        $clinic = $this->clinicContext->clinic();
        $key = $data['idempotency_key'] ?? null;

        // By reference: the key is kept only when the adjustment was recorded.
        $adjusted = false;
        $take = function () use ($bill, $data, $request, &$adjusted): RedirectResponse {
            try {
                app(PaymentService::class)->recordAdjustment($bill, (float) $data['amount'], $data['reason'], $request->user());
            } catch (DomainException|InvalidArgumentException $e) {
                return back()->withErrors(['billing' => $e->getMessage()]);
            }
            $adjusted = true;
            // The balance changed, so the plan made for the old one no longer fits: it is made again.
            app(BillInstalments::class)->clear($bill);

            return back()->with('success', 'Balance adjusted.');
        };

        if ($key === null) {
            $submissions->withoutKey($clinic, 'checkout.adjust');

            return $take();
        }

        return $submissions->onceRedirect($clinic, $request->user(), $key, "adjust:{$bill->id}", $take, function () use (&$adjusted): bool {
            return $adjusted;
        }, 'This adjustment was already recorded; it was not recorded twice.', atomic: true);
    }

    /**
     * A payment plan for what a bill still owes: dated instalments the patient agreed to, adding up to the balance. The payments
     * themselves go through the bill as ever and fill the instalments in order.
     */
    public function planInstalments(Request $request, PatientBilling $bill, BillInstalments $instalments): RedirectResponse
    {
        abort_unless($bill->clinic_id === $this->clinicContext->clinic()?->id, 404);
        $this->assertBranchId($bill->branch_id, ClinicPermission::PaymentCollect);
        $data = $request->validate([
            'instalments' => ['required', 'array', 'min:1', 'max:'.BillInstalments::MAX],
            'instalments.*.due_on' => ['required', 'date_format:Y-m-d'],
            'instalments.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ]);
        try {
            $instalments->plan($bill, $data['instalments'], $request->user(), $this->clinicContext->currentEmployee()?->id);
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['instalments' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment plan saved.');
    }

    /** Take the payment plan away; the bill and what was paid stay as they are. */
    public function clearInstalments(PatientBilling $bill, BillInstalments $instalments): RedirectResponse
    {
        abort_unless($bill->clinic_id === $this->clinicContext->clinic()?->id, 404);
        $this->assertBranchId($bill->branch_id, ClinicPermission::PaymentCollect);
        $instalments->clear($bill);

        return back()->with('success', 'Payment plan removed.');
    }

    /**
     * One signed consultation's checkout: what is left to bill for it (from
     * `EncounterCheckout`, which never offers a line already billed — the
     * check-in charge, a procedure, a treatment or stage — nor a session a
     * package paid for), its bills so far, and the patient's other open
     * bills at this branch. Billing work, so it needs `billing.create` at
     * the consultation's branch, never `billing.view`: the clinic's ledger is
     * not shown here.
     */
    public function show(Request $request, ClinicalEncounter $encounter): Response
    {
        $this->assertBranch($encounter, ClinicPermission::BillingCreate);
        $encounter->load(['patient' => fn ($query) => $query->withoutGlobalScopes(), 'doctor:id,name']);
        $user = $request->user();
        $clinic = $this->clinicContext->clinic();
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($encounter->branch_id);
        $collect = $user->hasClinicPermissionAt(ClinicPermission::PaymentCollect, $branch);

        $bills = PatientBilling::query()->where('clinic_id', $encounter->clinic_id)->where('encounter_id', $encounter->id)
            ->with(['items', 'payments'])->latest('id')->get();
        $openElsewhere = PatientBilling::query()->where('clinic_id', $encounter->clinic_id)->where('branch_id', $branch->id)
            ->where('patient_id', $encounter->patient_id)->where(fn ($query) => $query->whereNull('encounter_id')->orWhere('encounter_id', '!=', $encounter->id))
            ->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])->where('outstanding_balance', '>', 0)
            ->latest('id')->limit(20)->get();
        $billRow = fn (PatientBilling $bill) => [
            'id' => $bill->id,
            'number' => $bill->billing_number,
            'status' => $bill->status->value,
            'issued_at' => $bill->issued_at?->toIso8601String(),
            'total' => (float) $bill->final_amount,
            'outstanding' => (float) $bill->outstanding_balance,
            'pay_url' => $collect && (float) $bill->outstanding_balance > 0 && $bill->status !== BillingStatus::Cancelled ? route('clinic.billing.payments.store', $bill) : null,
            'print_url' => route('clinic.billing.bills.print', $bill),
        ];

        return Inertia::render('ClinicPortal/Billing/Checkout', [
            'encounter' => [
                'id' => $encounter->id,
                'number' => $encounter->encounter_number,
                'signed' => $encounter->isFinalized(),
                'doctor' => $encounter->doctor?->name,
                'follow_up_on' => $encounter->follow_up_on?->toDateString(),
                'follow_up_note' => $encounter->follow_up_note,
            ],
            'patient' => ['id' => $encounter->patient->id, 'name' => $encounter->patient->full_name, 'uhid' => $encounter->patient->uhid, 'url' => route('clinic.patients.show', $encounter->patient->id)],
            'lines' => $encounter->isFinalized() ? collect($this->checkout->lines($encounter))->map(fn (array $line) => [
                'key' => $line['key'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                // What the amount is: the consultation fee, a procedure done, or a treatment / stage charge at the amount agreed when it started.
                'kind' => match (true) {
                    $line['key'] === 'consultation' => 'consultation',
                    str_starts_with($line['key'], 'treatment:'), str_starts_with($line['key'], 'stage:') => 'agreed',
                    default => 'procedure',
                },
            ])->values()->all() : [],
            // A session a package paid for is not charged again (`SessionPackages::paidFor`).
            'package_covered' => app(SessionPackages::class)->paidFor($encounter),
            'bills' => $bills->map(fn (PatientBilling $bill) => [
                ...$billRow($bill),
                'paid' => (float) $bill->paid_amount - (float) $bill->refunded_amount,
                'items' => $bill->items->map(fn ($item) => ['description' => $item->description, 'quantity' => $item->quantity, 'amount' => (float) $item->final_amount])->values(),
                'payments' => $bill->payments->map(fn ($payment) => [
                    'receipt' => $payment->receipt_number, 'amount' => (float) $payment->amount, 'method' => $payment->payment_method?->label(),
                    'print_url' => route('clinic.billing.bills.print', ['bill' => $bill, 'layout' => 'receipt', 'payment' => $payment->id]),
                ])->values(),
            ])->values(),
            'open_bills' => $openElsewhere->map($billRow)->values(),
            // Only what the desk may take: no hand-typed gateway or insurance payments.
            'payment_methods' => app(PaymentService::class)->methodOptions($clinic, atDesk: true),
            'links' => [
                'index' => $user->hasClinicPermission(ClinicPermission::BillingView) ? route('clinic.billing.index') : null,
                'bill' => route('clinic.billing.checkout.store', $encounter),
                'book_follow_up' => $user->hasClinicPermission(ClinicPermission::AppointmentCreate) ? route('clinic.appointments.index') : null,
            ],
            'can' => [
                'bill' => $this->clinicContext->currentEmployee() !== null,
                'discount' => $user->hasClinicPermissionAt(ClinicPermission::DiscountApply, $branch),
                'collect' => $collect,
            ],
        ]);
    }

    /**
     * Bill a signed consultation's chosen lines and, when a payment is sent,
     * take it in the same transaction: a refused payment leaves no bill
     * behind. Without a payment the bill is raised to be paid later. A request
     * key made when the page opened bills once however often it is sent; a
     * refused attempt frees the key for the corrected retry. The amounts are
     * `EncounterCheckout`'s; a discount needs `discount.apply` at the branch.
     */
    public function store(Request $request, ClinicalEncounter $encounter, PaymentService $payments, IntakeSubmissions $submissions): RedirectResponse
    {
        $this->assertBranch($encounter, ClinicPermission::BillingCreate);
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($encounter->branch_id);
        $validated = $request->validate([
            'prices' => ['required', 'array', 'min:1'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment' => ['nullable', 'array'],
            'payment.method' => ['required_with:payment', 'string', 'max:30'],
            'payment.amount' => ['required_with:payment', 'numeric', 'gt:0'],
            'payment.reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ], ['prices.required' => 'Choose at least one line to bill.']);
        $user = $request->user();
        abort_if(($validated['discount'] ?? 0) > 0 && ! $user->hasClinicPermissionAt(ClinicPermission::DiscountApply, $branch), 403, 'You cannot give a discount.');
        $payment = $validated['payment'] ?? null;
        $method = null;
        if ($payment !== null) {
            abort_unless($user->hasClinicPermissionAt(ClinicPermission::PaymentCollect, $branch), 403, 'You cannot take payments.');
            $method = PaymentMethod::tryFrom((string) $payment['method']);
            if ($method === null || ! in_array($method, $payments->acceptedMethods($this->clinicContext->clinic(), atDesk: true), true)) {
                return back()->withErrors(['payment' => 'Choose how the patient is paying.']);
            }
        }
        $employee = $this->clinicContext->currentEmployee();
        if (! $employee) {
            return back()->withErrors(['checkout' => 'Your login has no staff record at this clinic, so it cannot raise bills. Ask the administrator to add you under Staff.']);
        }

        // By reference: the key is kept only when the bill was raised.
        $billed = false;
        $take = function () use ($encounter, $validated, $payment, $method, $payments, $employee, &$billed): RedirectResponse {
            try {
                [$bill, $receipt] = DB::transaction(function () use ($encounter, $validated, $payment, $method, $payments, $employee) {
                    $bill = $this->checkout->bill($encounter, $validated['prices'], $employee, (float) ($validated['discount'] ?? 0));
                    $receipt = $payment !== null
                        ? $payments->recordPayment($bill->fresh(), $method, (float) $payment['amount'], $payment['reference'] ?? null, $employee)
                        : null;

                    return [$bill->fresh(), $receipt];
                });
            } catch (DomainException|InvalidArgumentException $e) {
                return back()->withErrors(['checkout' => $e->getMessage()]);
            }
            $billed = true;

            return back()->with('success', $receipt
                ? "Bill {$bill->billing_number} raised and paid (receipt {$receipt->receipt_number})."
                : ((float) $bill->outstanding_balance > 0 ? "Bill {$bill->billing_number} raised, to be paid later." : "Bill {$bill->billing_number} raised."));
        };

        $key = $validated['idempotency_key'] ?? null;
        if ($key === null) {
            $submissions->withoutKey($this->clinicContext->clinic(), 'checkout.bill');

            return $take();
        }

        return $submissions->onceRedirect($this->clinicContext->clinic(), $user, $key, "checkout:{$encounter->id}", $take, function () use (&$billed): bool {
            return $billed;
        }, 'This consultation was already billed; it was not billed twice.', atomic: true);
    }

    /**
     * Take a payment against a bill. The Billing screen posts a form and is
     * redirected back; the desk's Collect Payment popup (after a check-in)
     * asks for JSON. Either sends a request key made when it opened, so a
     * double click or a retry records the payment once (`IntakeSubmissions`);
     * a second part payment is a new key. The amount is checked against the
     * bill's outstanding balance by `PaymentService`; nothing here sets what
     * is owed.
     */
    public function pay(Request $request, PatientBilling $bill, PaymentService $payments, IntakeSubmissions $submissions): RedirectResponse|JsonResponse
    {
        abort_unless($bill->clinic_id === $this->clinicContext->clinic()?->id, 404);
        $this->assertBranchId($bill->branch_id, ClinicPermission::PaymentCollect);
        $validated = $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ]);
        $clinic = $this->clinicContext->clinic();
        // The server decides who may type which method in: the desk never records a gateway or insurance settlement.
        if (! $payments->mayRecord($clinic, $request->user(), $bill->branch_id, PaymentMethod::from($validated['method']))) {
            $message = 'Choose how the patient is paying: that method is not recorded at the desk.';

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['method' => [$message]]], 422)
                : back()->withErrors(['method' => $message]);
        }
        $key = $validated['idempotency_key'] ?? null;
        if ($key === null) {
            $submissions->withoutKey($clinic, $request->expectsJson() ? 'checkout.pay.json' : 'checkout.pay.form');
        }

        if ($request->expectsJson()) {
            $take = function () use ($bill, $validated, $payments, $request): JsonResponse {
                try {
                    $payment = $payments->recordPayment($bill, PaymentMethod::from($validated['method']), (float) $validated['amount'], $validated['reference'] ?? null, $this->clinicContext->currentEmployee());
                } catch (DomainException|InvalidArgumentException $e) {
                    return response()->json(['message' => $e->getMessage(), 'errors' => ['amount' => [$e->getMessage()]]], 422);
                }

                return response()->json([
                    'message' => "Payment received (receipt {$payment->receipt_number}).",
                    'billing' => app(CheckInCharges::class)->present(['bill' => $bill->fresh(), 'payment' => $payment], $request->user()),
                ], 201);
            };

            return $submissions->once($clinic, $request->user(), $key, "payment:{$bill->id}", $take, atomic: true);
        }

        // By reference: the key is kept only when the payment was recorded.
        $paid = false;
        $take = function () use ($bill, $validated, $payments, &$paid): RedirectResponse {
            return $this->attempt(function () use ($bill, $validated, $payments, &$paid) {
                $payment = $payments->recordPayment($bill, PaymentMethod::from($validated['method']), (float) $validated['amount'], $validated['reference'] ?? null, $this->clinicContext->currentEmployee());
                $paid = true;

                return back()->with('success', "Payment received (receipt {$payment->receipt_number}).");
            });
        };

        return $key === null
            ? $take()
            : $submissions->onceRedirect($clinic, $request->user(), $key, "payform:{$bill->id}", $take, function () use (&$paid): bool {
                return $paid;
            }, 'This payment was already recorded; it was not taken twice.', atomic: true);
    }

    /** Where this payment's patient messages stand (`PaymentMessages`). */
    public function paymentMessages(Request $request, int $payment, PaymentMessages $messages): JsonResponse
    {
        return response()->json($messages->status($this->paymentHere($payment, ClinicPermission::PaymentCollect), $request->user()));
    }

    /** Send this payment's failed WhatsApp messages again; anything else is left alone. */
    public function retryPaymentMessages(Request $request, int $payment, PaymentMessages $messages): JsonResponse
    {
        $payment = $this->paymentHere($payment, ClinicPermission::PaymentCollect);
        $queued = $messages->retry($payment, $request->user());

        return response()->json([
            'message' => $queued > 0 ? 'Sending again.' : 'Nothing could be sent again. See each message for why.',
            ...$messages->status($payment, $request->user()),
        ]);
    }

    /** A payment of this clinic, at a branch this member may work in (and hold `$permission` at, when named). */
    private function paymentHere(int $id, ?ClinicPermission $permission = null): PatientBillingPayment
    {
        $payment = PatientBillingPayment::query()->withoutGlobalScopes()
            ->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($id);
        $this->assertBranchId($payment->branch_id, $permission);

        return $payment;
    }

    /**
     * Refund money collected on a bill, at most what was collected
     * (`PaymentService`). A request key made when the form opened records the
     * refund once however often it is sent.
     */
    public function refund(Request $request, PatientBilling $bill, PaymentService $payments, IntakeSubmissions $submissions): RedirectResponse
    {
        abort_unless($bill->clinic_id === $this->clinicContext->clinic()?->id, 404);
        $this->assertBranchId($bill->branch_id, ClinicPermission::RefundProcess);
        app(OwnerMode::class)->requireForSensitiveAction($request->user(), 'billing.refund', OwnerMode::STANDARD, ['bill_id' => $bill->id, 'branch_id' => $bill->branch_id]);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:80'],
        ], ['reason.required' => 'Say why the money is being refunded.']);
        $clinic = $this->clinicContext->clinic();
        $key = $validated['idempotency_key'] ?? null;

        // By reference: the key is kept only when the refund was recorded.
        $refunded = false;
        $take = function () use ($bill, $validated, $payments, &$refunded): RedirectResponse {
            return $this->attempt(function () use ($bill, $validated, $payments, &$refunded) {
                $refund = $payments->recordRefund(
                    $bill,
                    (float) $validated['amount'],
                    $validated['reason'],
                    $this->clinicContext->currentEmployee(),
                    null,
                    isset($validated['method']) ? PaymentMethod::from($validated['method']) : null,
                );
                $refunded = true;

                return back()->with('success', "Refund {$refund->refund_number} recorded.");
            });
        };

        if ($key === null) {
            $submissions->withoutKey($clinic, 'checkout.refund');

            return $take();
        }

        return $submissions->onceRedirect($clinic, $request->user(), $key, "refund:{$bill->id}", $take, function () use (&$refunded): bool {
            return $refunded;
        }, 'This refund was already recorded; it was not recorded twice.', atomic: true);
    }

    /**
     * The bill as a printable page: lines, totals, payments with their
     * receipt numbers, and refunds. Clinic time, clinic letterhead.
     *
     * `?layout=receipt` prints it as a 58 mm or 80 mm thermal roll receipt
     * (`width`, default 80), optionally for one of the bill's payments
     * (`payment`). Printing only reads: a reprint writes nothing but the
     * print's audit entry.
     */
    public function printBill(Request $request, PatientBilling $bill): HttpResponse
    {
        abort_unless($bill->clinic_id === $this->clinicContext->clinic()?->id, 404);
        $this->assertBranchId($bill->branch_id);
        // One bill, for whoever works with money at its branch: the ledger's readers, or the desk that billed or took the payment.
        abort_unless($this->mayHandleBill($request->user(), $bill->branch_id), 403);
        $bill->load(['items', 'payments', 'refunds', 'clinic', 'doctor:id,name', 'patient' => fn ($query) => $query->withoutGlobalScopes()]);
        $receipt = $request->query('layout') === 'receipt';
        $payment = $receipt && $request->filled('payment')
            ? ($bill->payments->firstWhere('id', (int) $request->query('payment')) ?? abort(404))
            : null;
        $width = (int) $request->query('width') === 58 ? 58 : 80;
        app(SecurityAudit::class)->record('billing.printed', $bill, array_filter([
            'billing_number' => $bill->billing_number,
            'layout' => $receipt ? "receipt_{$width}mm" : null,
            'receipt_number' => $payment?->receipt_number,
        ]));

        if ($receipt) {
            return response()->view('clinic.bill_receipt_thermal', [
                'bill' => $bill,
                'clinic' => $bill->clinic,
                'branch' => Branch::query()->withoutGlobalScopes()->find($bill->branch_id),
                'patient' => $bill->patient,
                'payment' => $payment,
                'width' => $width,
            ]);
        }

        return response()->view('clinic.bill_printable', [
            'bill' => $bill,
            'clinic' => $bill->clinic,
            'branch' => Branch::query()->withoutGlobalScopes()->find($bill->branch_id),
            'patient' => $bill->patient,
        ]);
    }

    private function assertBranch(ClinicalEncounter $encounter, ?ClinicPermission $permission = null): void
    {
        abort_unless($encounter->clinic_id === $this->clinicContext->clinic()?->id, 404);
        $this->assertBranchId($encounter->branch_id, $permission);
    }

    /** Whether this member works with money at that branch: reads the ledger, raises bills or takes payments there. */
    private function mayHandleBill(?User $user, ?int $branchId): bool
    {
        $branch = $branchId ? Branch::query()->withoutGlobalScopes()->find($branchId) : null;

        return $user !== null && $branch !== null && collect([ClinicPermission::BillingView, ClinicPermission::BillingCreate, ClinicPermission::PaymentCollect])
            ->contains(fn (ClinicPermission $permission) => $user->hasClinicPermissionAt($permission, $branch));
    }

    private function assertBranchId(?int $branchId, ?ClinicPermission $permission = null): void
    {
        $membership = $this->clinicContext->membership() ?? abort(403);
        $branch = $branchId ? Branch::query()->withoutGlobalScopes()->find($branchId) : null;
        abort_unless($branch && $membership->hasAccessToBranch($branch), 403);

        // A financial permission counts at the record's own branch, not the session's.
        if ($permission !== null) {
            abort_unless(auth()->user()?->hasClinicPermissionAt($permission, $branch) ?? false, 403);
        }
    }

    /**
     * @param  Closure(): RedirectResponse  $action
     */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->withErrors(['checkout' => $e->getMessage()]);
        }
    }
}
