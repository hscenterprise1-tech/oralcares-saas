<?php

/*
|--------------------------------------------------------------------------
| Clinic modules sold separately
|--------------------------------------------------------------------------
|
| A module here is available to a clinic when, in order:
|   1. OralCares set an override for the clinic (`clinic_module_overrides`);
|   2. else its plan includes the feature (`company_plan_features`, key below)
|      or an active add-on sells it (`company_subscription_addons`,
|      addon_type "module:<key>");
|   3. else `default_included` — what every clinic gets until the product
|      decides to sell the module.
|
| Selling a module therefore needs no code: attach its feature to plans or
| sell the add-on, then set `default_included` to false.
|
*/

return [
    // Queue positions at the desk. Not sold separately yet: every clinic has it.
    'queue' => ['label' => 'Queue', 'default_included' => true],
];
