<?php

use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicBookingController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::resource('event-types', EventTypeController::class);

    Route::get('/availability', [AvailabilityController::class, 'edit'])->name('availability.edit');
    Route::put('/availability', [AvailabilityController::class, 'update'])->name('availability.update');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::patch('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// Public booking routes — registered LAST to avoid shadowing all named routes above
Route::get('/{username}/{slug}', [PublicBookingController::class, 'show'])->name('booking.show');
Route::post('/{username}/{slug}', [PublicBookingController::class, 'store'])->name('booking.store');
Route::get('/{username}/{slug}/confirmation/{booking}', [PublicBookingController::class, 'confirmation'])->name('booking.confirmation');
