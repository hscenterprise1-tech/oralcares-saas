<?php

namespace App;

/**
 * What an organization is *to us*.
 *
 * `organizations` is the tenant table — 54 tables carry `organization_id` — and
 * that is correct. What was missing is the one fact that separates the company
 * running the software from the clinics it sells to. Nothing recorded it, so
 * the codebase guessed three different ways:
 *
 *   - `Organization::where('name', 'OralCares HQ')` — rename the row and the
 *     product breaks; name a customer that and it becomes the seller
 *   - `Organization::min('id')` — whoever was inserted first
 *   - `whereHas('wonFromLeads')->orWhereHas('clinics')` — which is why the
 *     Accounts directory reads empty: a customer provisioned a moment ago has
 *     neither yet
 *
 * `OrganizationType` does not answer this. It says what kind of healthcare
 * business a row is — dental clinic, hospital, diagnostic centre — which every
 * customer has and which the seller also has to carry something for.
 */
enum OrganizationRole: string
{
    /** The company running OralCares. Sells, does not get sold to. */
    case Provider = 'provider';

    /** A clinic or hospital we sold to. Trialling, live, or churned. */
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Provider => 'Provider',
            self::Customer => 'Customer',
        };
    }
}
