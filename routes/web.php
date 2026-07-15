<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

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
