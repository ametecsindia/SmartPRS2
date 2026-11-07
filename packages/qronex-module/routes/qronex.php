<?php

use Ametecs\Qronex\Http\Controllers\Admin;
use Ametecs\Qronex\Http\Controllers\Api\MobileController;
use Ametecs\Qronex\Http\Controllers\DisplayController;
use Ametecs\Qronex\Http\Middleware\AdminAccess;
use Ametecs\Qronex\Http\Middleware\DeviceToken;
use Illuminate\Support\Facades\Route;

$p = config('qronex.admin_prefix', 'qronex');

// Phone API — same endpoints and rules as QRONEX's /api/v1.
Route::prefix('api/qronex/v1')->middleware('api')->group(function () {
    Route::post('activate', [MobileController::class, 'activate'])->middleware('throttle:10,1');
    Route::middleware(DeviceToken::class)->group(function () {
        Route::get('me', [MobileController::class, 'me'])->middleware('throttle:60,1');
        Route::post('scan', [MobileController::class, 'scan'])->middleware('throttle:20,1');
        Route::get('history', [MobileController::class, 'history'])->middleware('throttle:30,1');
        Route::post('remote-scan', [MobileController::class, 'remoteScan'])->middleware('throttle:20,1');
        Route::post('remote-requests', [MobileController::class, 'requestRemote'])->middleware('throttle:10,1');
        Route::post('corrections', [MobileController::class, 'requestCorrection'])->middleware('throttle:10,1');
        Route::get('requests', [MobileController::class, 'requests'])->middleware('throttle:30,1');
    });
});

// Entrance displays (public; the link carries the gate's secret) and static assets.
Route::prefix($p)->middleware('web')->group(function () {
    Route::get('display/{gate}', [DisplayController::class, 'show'])->whereNumber('gate')->name('qronex.display');
    Route::get('display/{gate}/token', [DisplayController::class, 'token'])->whereNumber('gate')->middleware('throttle:120,1')->name('qronex.display.token');
    // ?f=… not /assets/x.css: servers like nginx answer *.css / *.js paths from disk and never reach Laravel.
    Route::get('asset', Admin\AssetController::class)->name('qronex.asset');
});

// One-time sign-in link for hosts whose console uses API tokens (EPT): issue via the host's authenticated API, consume here.
Route::get("$p/sso", [Admin\SsoController::class, 'consume'])->middleware(['web', 'signed'])->name('qronex.sso');

// Admin module.
Route::prefix($p)->middleware(config('qronex.admin_middleware', ['web']))->name('qronex.')->group(function () {
    Route::middleware(AdminAccess::class.':view')->group(function () {
        Route::get('/', Admin\DashboardController::class)->name('dashboard');
        Route::get('attendance', [Admin\AttendanceController::class, 'index'])->name('attendance');
        Route::get('attendance/export.csv', [Admin\AttendanceController::class, 'export'])->name('attendance.export');
        Route::get('devices', [Admin\DeviceController::class, 'index'])->name('devices');
        Route::get('devices/{device}', [Admin\DeviceController::class, 'show'])->whereNumber('device')->name('devices.show');
        Route::get('employees', [Admin\EmployeeController::class, 'index'])->name('employees');
        Route::get('offices', [Admin\OfficeController::class, 'index'])->name('offices');
        Route::get('approvals', [Admin\ApprovalsController::class, 'index'])->name('approvals');
        Route::get('remote', [Admin\RemoteController::class, 'index'])->name('remote');
        Route::get('settings', [Admin\SettingsController::class, 'show'])->name('settings');
    });
    Route::middleware(AdminAccess::class.':approve')->group(function () {
        Route::post('devices/{device}/{action}', [Admin\DeviceController::class, 'act'])->whereNumber('device')->whereIn('action', ['approve', 'reject', 'revoke'])->name('devices.act');
        Route::post('approvals/remote/{permission}', [Admin\ApprovalsController::class, 'remote'])->name('approvals.remote');
        Route::post('approvals/corrections/{correction}', [Admin\ApprovalsController::class, 'correction'])->name('approvals.correction');
        Route::post('remote', [Admin\RemoteController::class, 'store'])->name('remote.store');
        Route::post('remote/{permission}/revoke', [Admin\RemoteController::class, 'revoke'])->name('remote.revoke');
    });
    Route::middleware(AdminAccess::class.':manage')->group(function () {
        Route::post('employees/sync', [Admin\EmployeeController::class, 'sync'])->name('employees.sync');
        Route::get('employees/{employee}/edit', [Admin\EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('employees/{employee}', [Admin\EmployeeController::class, 'update'])->name('employees.update');
        Route::post('employees/{employee}/activation', [Admin\EmployeeController::class, 'activation'])->name('employees.activation');
        Route::get('offices/create', [Admin\OfficeController::class, 'create'])->name('offices.create');
        Route::post('offices', [Admin\OfficeController::class, 'store'])->name('offices.store');
        Route::get('offices/{office}/edit', [Admin\OfficeController::class, 'edit'])->name('offices.edit');
        Route::put('offices/{office}', [Admin\OfficeController::class, 'update'])->name('offices.update');
        Route::post('offices/{office}/gates', [Admin\OfficeController::class, 'storeGate'])->name('gates.store');
        Route::post('gates/{gate}/toggle', [Admin\OfficeController::class, 'toggleGate'])->name('gates.toggle');
        Route::post('gates/{gate}/reset', [Admin\OfficeController::class, 'resetGate'])->name('gates.reset');
    });
    Route::middleware(AdminAccess::class.':configure')->group(function () {
        Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/toggle', [Admin\SettingsController::class, 'toggle'])->name('settings.toggle');
    });
});
