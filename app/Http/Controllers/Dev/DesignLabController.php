<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DesignLabController extends Controller
{
    /**
     * Display the OralCares Design Lab.
     * Only accessible in local development environments.
     */
    public function index(): Response
    {
        abort_unless(app()->environment('local'), 404);

        return Inertia::render('Dev/DesignLab');
    }
}
