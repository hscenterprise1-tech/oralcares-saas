<?php

namespace App\Support;

use App\Support\ClinicAccess\ClinicAccess;
use Illuminate\Support\Facades\Route;

/**
 * The role workspaces: one primary daily surface per responsibility.
 *
 * - Command Center: the owner's or administrator's view of the clinic.
 * - Reception: the front office runs the day's patients from it (the Reception Control Center).
 * - Today: a practitioner's patients and clinical work.
 * - Billing: the work center of someone whose job is billing alone.
 *
 * Which ones a user has is `ClinicAccess::workspaces()` (the one rule
 * engine); this registry only names them and says where each one lives.
 * `clinic.dashboard` opens the first (primary) one; a user with more than one
 * switches between them from the shell.
 */
class ClinicWorkspaces
{
    public const WORKSPACES = [
        'command_center' => ['label' => 'Command Center', 'icon' => 'command-center', 'route' => 'clinic.dashboard'],
        'front_desk' => ['label' => 'Reception', 'icon' => 'front-desk', 'route' => 'clinic.reception'],
        'today' => ['label' => 'Today', 'icon' => 'doctor', 'route' => 'clinic.consultations.index'],
        'billing' => ['label' => 'Billing', 'icon' => 'billing', 'route' => 'clinic.billing.index'],
    ];

    /**
     * The user's workspaces, primary first.
     *
     * @param  array<string, string>  $routeParameters
     * @return list<array{key: string, label: string, icon: string, href: string, primary: bool}>
     */
    public static function for(ClinicAccess $access, array $routeParameters = []): array
    {
        $resolved = [];
        foreach ($access->workspaces() as $index => $key) {
            $workspace = self::WORKSPACES[$key];
            if (! Route::has($workspace['route'])) {
                continue;
            }
            $resolved[] = [
                'key' => $key,
                'label' => $workspace['label'],
                'icon' => $workspace['icon'],
                'href' => route($workspace['route'], $routeParameters, false),
                'primary' => $index === 0,
            ];
        }

        return $resolved;
    }

    /**
     * Where a signed-in user lands (after login, onboarding, an invitation, or
     * the logo): the one Dashboard, composed for them from what they may do
     * (`DashboardWidgets`). Nobody is sent to a separate per-role home, so a
     * receptionist who may also bill keeps one dashboard with both.
     *
     * @param  array<string, string>  $routeParameters
     */
    public static function landing(ClinicAccess $access, array $routeParameters = []): string
    {
        return route('clinic.dashboard', $routeParameters);
    }
}
