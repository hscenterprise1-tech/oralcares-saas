# Subscriptions and plan entitlements

```
Clinic ─► CompanySubscription (clinic_id) ─► CompanyPlan ─► plan features (module keys)
       ─► EntitlementResolver::forClinic() ─► modules + window + limits
          ├─► nav filter (HandleInertiaRequests → enabled_modules)      presentation only
          ├─► clinic_entitled:{module} route middleware                 enforcement
          └─► subscription_window middleware (trial / grace / lapsed)   enforcement
```

The navigation is never the control. `clinic_entitled:patients` and
`clinic_entitled:appointments` refuse a module the plan lacks, even to the
owner. A plan with no features gets the fallback modules: patients,
appointments, billing.

## Legacy exemption and backfill

A clinic with **no subscription row** is currently exempt from both
middlewares, so clinics created before subscriptions existed were not locked
out. This is a gap: such a clinic bypasses plan limits.

`php artisan subscriptions:backfill-legacy` (a dry run by default,
`--apply` to change data):

1. If the organization has exactly one unlinked subscription and one
   clinic, it links that subscription.
2. Otherwise it creates an active subscription with no plan (fallback
   modules), with limits no lower than the clinic's current members and
   branches. No clinic loses access.
3. A clinic with no organization is skipped and reported for a person to
   fix.

Every change is audited (`subscription.backfilled`). Picking the right
commercial plan afterwards is a sales decision.

Local dry run (2026-09-29): 4 clinics would get a legacy subscription. 3
clinics (ids 1–3) have no organization.

**Removing the exemption:** once `security:production-check` reports
"Every active clinic has a subscription" in production, drop the
`has_subscription` early-return in `EnsureClinicEntitled` (and decide the
same for `EnforceSubscriptionWindow`). Until then it stays, deliberately.

## Where to add enforcement next

Each new module route group gets `clinic_entitled:{module key}` matching its
plan feature key. Seat and branch limits are enforced by
`ResourceLimitService` at invite and branch creation.
