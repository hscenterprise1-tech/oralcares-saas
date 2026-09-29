<?php

namespace App\Http\Controllers\Public;

use App\ClinicType;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\OrganizationRole;
use App\Scopes\OrganizationScope;
use App\Services\LeadWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public door into the pipeline.
 *
 * Until now the WhatsApp webhook was the only way a lead could reach the system
 * without a login — every other lead route sits behind the company portal — so
 * a form on the marketing site had nowhere to send anyone. This is that
 * destination.
 *
 * It deliberately does very little of its own: validation, spam defence, and
 * then straight into `LeadWorkspaceService::createLead`, which is the same path
 * the internal form uses. That means duplicate detection, activity logging,
 * scoring, next-best-action and auto-assignment all happen exactly as they do
 * for a lead typed in by hand — a lead that arrived at 2am from the website is
 * owned by somebody by 2:01.
 */
class LeadIntakeController extends Controller
{
    public function __construct(protected LeadWorkspaceService $workspaceService)
    {
        /*
         * Off until the marketing site exists.
         *
         * The form is finished and verified end to end, but there is nowhere to
         * link it from yet, and an open unauthenticated endpoint that writes
         * rows should not sit exposed with no purpose. Checked here rather than
         * at route registration so it can be switched on without a deploy — and
         * so tests can exercise the real path.
         */
        abort_unless(config('crm.public_intake_enabled', false), 404);
    }

    /**
     * The form itself.
     *
     * Rendered by the app rather than embedded on a separate marketing site so
     * there is one place to change the fields. A site can still post to it
     * directly from its own markup.
     */
    public function show(): Response
    {
        /*
         * `ClinicType` is the vocabulary the business already uses for what a
         * customer is — Dental Clinic, IVF, Physiotherapy, Multi-speciality and
         * the rest. Reusing it means a prospect describes themselves in the same
         * words the plans are sold in, rather than a second list that has to be
         * mapped later.
         */
        return Inertia::render('Public/LeadIntake', [
            'clinicTypes' => array_map(
                fn (ClinicType $type) => ['value' => $type->value, 'label' => $type->value],
                ClinicType::cases()
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /*
         * A hidden field no human ever sees, filled in only by bots that
         * complete every input on a page. Cheaper than a CAPTCHA, invisible to
         * real visitors, and it costs nothing when it is empty.
         *
         * The response is a success either way: telling a bot it was detected
         * only teaches it to try again differently.
         */
        if (filled($request->input('website_url'))) {
            return back()->with('success', 'Thanks — we will be in touch shortly.');
        }

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'healthcare_specialty' => ['nullable', Rule::in(array_column(ClinicType::cases(), 'value'))],
            'branch_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'company_name.required' => 'Please tell us the name of your clinic or hospital.',
            'contact_name.required' => 'Please tell us who we should ask for.',
            'phone.required' => 'We need a phone number to reach you on.',
        ]);

        $organizationId = $this->resolveOwningOrganization();

        if (! $organizationId) {
            /*
             * No organization to attach the lead to means it would be created
             * invisible to everyone — worse than a visible failure, because the
             * enquiry is silently lost. Fail loudly instead.
             */
            return back()->withErrors([
                'company_name' => 'We could not accept the form just now. Please call us instead.',
            ])->withInput();
        }

        /*
         * Only the fields the visitor actually filled in.
         *
         * Passing `null` for an untouched optional field is not the same as
         * omitting it: several lead columns are NOT NULL with a database
         * default, so an explicit null overrides the default and the insert
         * fails. Omitting the key lets the column default apply, which is what
         * a blank form field means.
         */
        $attributes = array_filter([
            'company_name' => $validated['company_name'],
            'contact_name' => $validated['contact_name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'healthcare_specialty' => $validated['healthcare_specialty'] ?? null,
            'branch_count' => $validated['branch_count'] ?? null,
            'followup_notes' => $validated['message'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        /*
         * The second place the organisation boundary is lifted on an HTTP
         * request, and for the same reason as the WhatsApp webhook: this door
         * is public by design, so there is no user to resolve a tenant from.
         *
         * `createLead()` does not only insert. It runs a duplicate check
         * against existing leads and reloads the row with `fresh()` before
         * returning it — both reads, both scoped. Under the fail-closed rule
         * the duplicate check would match nothing (so every enquiry would look
         * novel) and `fresh()` would come back null. The visitor would still
         * see "thanks"; the tenant is chosen explicitly above and passed in, so
         * nothing here depends on the boundary to decide ownership.
         */
        OrganizationScope::asSystem(fn () => $this->workspaceService->createLead([
            ...$attributes,
            'organization_id' => $organizationId,
            'source' => 'website',
        ]));

        return back()->with('success', 'Thanks — we will be in touch shortly.');
    }

    /**
     * Which organization owns leads that arrive with no logged-in user.
     *
     * Configurable, because the answer stops being obvious the moment there is
     * more than one seller.
     *
     * The fallback is the oldest *provider*, not the oldest organization. A
     * lead's tenant is the organisation selling, and `Lead` now refuses a
     * customer outright — so the old fallback could have handed a public form a
     * customer account and turned a visitor's enquiry into a 500. It is also
     * the same conflation that produced finding T-2 in the first place.
     */
    protected function resolveOwningOrganization(): ?int
    {
        $configured = config('crm.public_intake_organization_id');

        if ($configured && Organization::whereKey($configured)->exists()) {
            return (int) $configured;
        }

        return Organization::query()
            ->where('role', OrganizationRole::Provider->value)
            ->orderBy('id')
            ->value('id');
    }
}
