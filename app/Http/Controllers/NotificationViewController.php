<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class NotificationViewController extends Controller
{
    public function index(): View
    {
        return view('notifications.index');
    }

    public function preferences(): View
    {
        return view('notifications.preferences');
    }
}
