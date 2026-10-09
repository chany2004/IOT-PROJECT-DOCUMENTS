<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AuthController, ManagementController, MonitoringController, WatcherTelegramController};

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate']);
});
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/account', [AuthController::class, 'account'])->name('account');
    Route::put('/account/password', [AuthController::class, 'password'])->name('account.password');
    Route::get('/dashboard', [MonitoringController::class, 'dashboard'])->name('dashboard');
    Route::get('/monitor/feed', [MonitoringController::class, 'feed'])->name('monitor.feed');
    Route::get('/events', [MonitoringController::class, 'events'])->name('events.index');
    Route::get('/events/{event}', [MonitoringController::class, 'show'])->name('events.show');
    Route::post('/events/{event}/acknowledge', [MonitoringController::class, 'acknowledge'])->name('events.acknowledge');
    Route::patch('/events/{event}', [MonitoringController::class, 'update'])->name('events.update');
    Route::post('/events/{event}/watcher-sms/retry', [MonitoringController::class, 'retryWatcherSms'])->name('events.watcher-sms.retry');
    Route::post('/events/{event}/watcher-telegram/retry', [MonitoringController::class, 'retryWatcherTelegram'])->name('events.watcher-telegram.retry');
    Route::get('/events/{event}/image', [MonitoringController::class, 'image'])->name('events.image');
    Route::get('/patients', [ManagementController::class, 'patients'])->name('patients.index');
    Route::get('/devices', [ManagementController::class, 'devices'])->name('devices.index');
    Route::middleware('admin')->group(function () {
        Route::get('/patients/create', [ManagementController::class, 'patientForm'])->name('patients.create');
        Route::get('/patients/{patient}/edit', [ManagementController::class, 'patientForm'])->name('patients.edit');
        Route::post('/patients', [ManagementController::class, 'savePatient'])->name('patients.store');
        Route::put('/patients/{patient}', [ManagementController::class, 'savePatient'])->name('patients.update');
        Route::get('/rooms', [ManagementController::class, 'rooms'])->name('rooms.index');
        Route::post('/rooms', [ManagementController::class, 'saveRoom'])->name('rooms.store');
        Route::put('/rooms/{room}', [ManagementController::class, 'saveRoom'])->name('rooms.update');
        Route::post('/rooms/{room}/assign', [ManagementController::class, 'assignRoom'])->name('rooms.assign');
        Route::post('/devices', [ManagementController::class, 'saveDevice'])->name('devices.store');
        Route::put('/devices/{device}', [ManagementController::class, 'saveDevice'])->name('devices.update');
        Route::post('/devices/{device}/assign', [ManagementController::class, 'assign'])->name('devices.assign');
        Route::post('/devices/{device}/rotate-key', [ManagementController::class, 'rotate'])->name('devices.rotate');
    Route::get('/users', [ManagementController::class, 'users'])->name('users.index');
        Route::post('/users', [ManagementController::class, 'saveUser'])->name('users.store');
        Route::put('/users/{user}', [ManagementController::class, 'saveUser'])->name('users.update');
    });
    Route::get('/patients/{patient}', [ManagementController::class, 'patient'])->name('patients.show');
    Route::get('/patients/{patient}/watcher-telegram/status', [WatcherTelegramController::class, 'status'])->name('patients.watcher-telegram.status');
    Route::post('/patients/{patient}/watcher-telegram/connect', [WatcherTelegramController::class, 'connect'])->name('patients.watcher-telegram.connect');
    Route::delete('/patients/{patient}/watcher-telegram', [WatcherTelegramController::class, 'disconnect'])->name('patients.watcher-telegram.disconnect');
});
