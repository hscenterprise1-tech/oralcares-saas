<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stale Lead Threshold
    |--------------------------------------------------------------------------
    |
    | Days a lead may sit in the same pipeline stage before Sales Operations
    | flags it as stale. Terminal stages (won/lost) are always excluded.
    | Staleness is reported only — a lead's stage is never changed
    | automatically, because a quiet deal is not necessarily a dead one.
    |
    */
    'stale_lead_days' => env('CRM_STALE_LEAD_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Quotation Approval Threshold
    |--------------------------------------------------------------------------
    |
    | Discount percentage at or above which a quotation requires management
    | approval before it may be sent. Set to null to disable the gate.
    |
    | A sales employee may discount up to — but not including — this figure on
    | their own authority. Anything at or beyond it waits for an admin.
    |
    */
    'quotation_approval_discount_percent' => env('CRM_QUOTATION_APPROVAL_DISCOUNT_PERCENT', 5),

    /*
    |--------------------------------------------------------------------------
    | Tenant Base Domain
    |--------------------------------------------------------------------------
    |
    | The domain a provisioned clinic's subdomain is displayed against —
    | `{clinic.slug}.{this}` — on the Deal 360 trial hero and the handover
    | message. This is display-only: no DNS, vhost, or wildcard routing is
    | wired to it. `clinics.slug` is a database-unique string, not a claim
    | that the subdomain resolves; provisioning real DNS for a tenant is
    | infrastructure this config does not attempt to describe.
    |
    */
    'tenant_base_domain' => env('CRM_TENANT_BASE_DOMAIN', 'oralcares.in'),

    /*
    |--------------------------------------------------------------------------
    | Trial Lengths
    |--------------------------------------------------------------------------
    |
    | The two trial lengths sales may offer, and which one is used when nobody
    | chooses. A plan's own `trial_duration_days` still wins when it is set, so
    | a plan can carry a longer evaluation without changing this list.
    |
    | These are the lengths offered, not a hard limit: an extension can legally
    | take a trial past the longest option here, which is why the absolute
    | ceiling below is separate.
    |
    */
    'trial_length_options' => [7, 14],

    'trial_default_days' => env('CRM_TRIAL_DEFAULT_DAYS', 14),

    /*
    | The outer bound on any single trial, extensions included. Guards against
    | a typo turning a two-week evaluation into a free year.
    */
    'trial_max_days' => env('CRM_TRIAL_MAX_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Fallback Lead Owner
    |--------------------------------------------------------------------------
    |
    | Who receives a lead that no assignment rule claimed. Leave null and it
    | resolves to the master admin, which is correct while one person runs
    | sales; set it to a sales manager once there is one.
    |
    | A lead must never end up owned by nobody — that is not a gap to report on
    | a dashboard, it is a lead nobody is working.
    |
    */
    'fallback_lead_owner_id' => env('CRM_FALLBACK_LEAD_OWNER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Public Enquiry Owner
    |--------------------------------------------------------------------------
    |
    | Which organization owns leads arriving through the public form, where
    | there is no logged-in user to infer it from. Left null it resolves to the
    | oldest organization, which is the house account while there is only one.
    |
    */
    'public_intake_organization_id' => env('CRM_PUBLIC_INTAKE_ORGANIZATION_ID'),

    /*
    |--------------------------------------------------------------------------
    | Public Enquiry Form
    |--------------------------------------------------------------------------
    |
    | Off until the marketing website is built and has somewhere to link from.
    | The form itself is complete and covered by tests; this only decides
    | whether the public route answers. Set CRM_PUBLIC_INTAKE_ENABLED=true to
    | open it.
    |
    */
    'public_intake_enabled' => env('CRM_PUBLIC_INTAKE_ENABLED', false),

];
