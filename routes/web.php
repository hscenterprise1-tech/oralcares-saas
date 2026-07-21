<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified', 'clinic_context'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

use App\Http\Controllers\Master\ClinicController;
use App\Http\Controllers\Master\LeadController;
use App\Http\Controllers\Master\MasterDashboardController;

Route::prefix('master')->name('master.')->middleware(['auth', 'master_admin'])->group(function () {
    Route::get('/', [MasterDashboardController::class, 'index'])->name('index');
    Route::get('/dashboard', [MasterDashboardController::class, 'index'])->name('dashboard');
    Route::get('/clinics', [ClinicController::class, 'index'])->name('clinics.index');
    Route::get('/clinics/create', [ClinicController::class, 'create'])->name('clinics.create');
    Route::post('/clinics', [ClinicController::class, 'store'])->name('clinics.store');
    Route::get('/clinics/{clinic}', [ClinicController::class, 'show'])->name('clinics.show');

    // CRM Lead Management Routes
    Route::resource('leads', LeadController::class)->names('leads');
    Route::post('leads/{lead}/notes', [LeadController::class, 'storeNote'])->name('leads.notes.store');
});

require __DIR__.'/auth.php';

use App\Shared\Notification\Controllers\NotificationController;
use App\Shared\Notification\Controllers\NotificationPreferenceController;
use App\Http\Controllers\NotificationViewController;

Route::middleware(['auth'])->group(function () {
    // Web Views
    Route::get('/notifications', [NotificationViewController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/preferences', [NotificationViewController::class, 'preferences'])->name('notifications.preferences.show');

    // API endpoints (AJAX)
    Route::prefix('api/notifications')->name('api.notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unreadCount');
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('markAllAsRead');
        Route::post('/bulk', [NotificationController::class, 'bulkAction'])->name('bulk');
        Route::patch('/{id}/read', [NotificationController::class, 'markAsRead'])->name('read');
        Route::patch('/{id}/archive', [NotificationController::class, 'archive'])->name('archive');
        
        Route::get('/preferences', [NotificationPreferenceController::class, 'show'])->name('preferences.show');
        Route::post('/preferences', [NotificationPreferenceController::class, 'update'])->name('preferences.update');
    });
});
