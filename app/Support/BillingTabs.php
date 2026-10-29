<?php

namespace App\Support;

use App\Support\ClinicAccess\ClinicAccess;

/**
 * The Billing area's sections, as tabs inside the one Billing destination of
 * the sidebar (no section is a navigation entry of its own). Each tab is its
 * route's own rule, decided on the server: a section this member may not open
 * is never offered, and a section without a finished page is never linked.
 */
final class BillingTabs
{
    /**
     * @var list<array{key: string, label: string, route: string, requires: array<string, mixed>}>
     */
    public const TABS = [
        ['key' => 'bills', 'label' => 'Bills & Collections', 'route' => 'clinic.billing.index',
            'requires' => ['permissions' => ['billing.view'], 'workspace' => ['command_center', 'front_desk', 'billing']]],
        ['key' => 'reversals', 'label' => 'Refunds & Adjustments', 'route' => 'clinic.billing.reversals',
            'requires' => ['permissions' => ['billing.view'], 'workspace' => ['command_center', 'front_desk', 'billing']]],
        ['key' => 'expenses', 'label' => 'Expenses', 'route' => 'clinic.expenses.index',
            'requires' => ['permissions' => ['expense.manage']]],
        ['key' => 'summary', 'label' => 'Financial Summaries', 'route' => 'clinic.billing.summary',
            'requires' => ['permissions' => ['billing.view', 'reports.view'], 'workspace' => ['command_center']]],
    ];

    /**
     * @param  array<string, string>  $routeParameters
     * @return list<array{key: string, label: string, href: string}>
     */
    public static function for(ClinicAccess $access, array $routeParameters = []): array
    {
        $tabs = [];
        foreach (self::TABS as $tab) {
            if (ClinicFrontend::available($tab['route']) && $access->allows(['route' => $tab['route']] + $tab['requires'])) {
                $tabs[] = ['key' => $tab['key'], 'label' => $tab['label'], 'href' => route($tab['route'], $routeParameters, false)];
            }
        }

        return $tabs;
    }
}
