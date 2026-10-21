<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in member's notifications at this clinic, as a page.
 *
 * The list itself comes from the shared notification API, which binds every
 * read and change to the clinic of this session (`NotificationController::
 * clinicScope`), so a member of two clinics only ever sees this one's.
 */
class ClinicNotificationsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ClinicPortal/Notifications', [
            'links' => [
                // `__ID__` is replaced with a notification's id in the page.
                'read' => route('api.notifications.read', ['id' => '__ID__'], false),
            ],
        ]);
    }
}
