<?php

namespace App\Http\Controllers\Company\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * The one canonical Settings destination for Company/HQ.
     *
     * Renders no privileged data itself: every section is either a structural
     * placeholder or a card that deep-links to the existing, already-gated
     * page for that concern (Marketing Settings, Roles & Permissions, Leave
     * Types, ...). Each link's own route still enforces its own permission on
     * arrival — this page is a directory, not a new authorization surface.
     */
    public function index(Request $request): Response
    {
        /*
         * Open to any authenticated Company user, deliberately.
         *
         * The page discloses nothing itself: every section is either a
         * structural placeholder or a card whose own destination route
         * carries its own permission, exactly as it did before this page
         * existed. Gating the directory on, say, `manage-settings` — held
         * only by Super Admin — would 403 a Marketing or People manager for
         * reaching their own module's settings through their module rail's
         * Settings icon, which is the exact "menu offers, route refuses"
         * failure the rest of this navigation has been fixed to avoid.
         */
        abort_unless($request->user() !== null, 403);

        return Inertia::render('Company/Administration/Settings/Index', [
            'tab' => $request->input('tab'),
        ]);
    }
}
