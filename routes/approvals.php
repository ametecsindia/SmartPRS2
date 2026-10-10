<?php

use App\Http\Controllers\LeaveController;
use App\Http\Controllers\RequestController;
use Illuminate\Support\Facades\Route;

/*
| 30 Sep 2026 (Ejaz) — Admin / HR adjust a PENDING request before deciding it.
| Mounted from bootstrap/app.php with the /app stack (routes/web.php untouched).
|   POST /app/requests/{module}/{id}/edit   sanctioned/approved amount, EMI, tenure, expense date
|   POST /app/leaves/{id}/edit              From / To dates (days recomputed)
*/

Route::post('/app/requests/{module}/{id}/edit', [RequestController::class, 'editFields'])->whereNumber('id')->name('app.requests.edit');
Route::post('/app/leaves/{id}/edit', [LeaveController::class, 'edit'])->whereNumber('id')->name('app.leaves.edit');

/*
| 30 Sep 2026 (Ejaz) — Raise a Complaint: POSH Internal Committee + evidence files
| (App\Http\Controllers\ComplaintController — same visibility rule everywhere).
*/
Route::get('/app/complaints/committee', [App\Http\Controllers\ComplaintController::class, 'committee'])->name('app.complaints.committee');
Route::post('/app/complaints/committee', [App\Http\Controllers\ComplaintController::class, 'saveCommittee'])->name('app.complaints.committee.save');
Route::get('/app/complaints/{id}/evidence', [App\Http\Controllers\ComplaintController::class, 'evidence'])->whereNumber('id')->name('app.complaints.evidence');
Route::post('/app/complaints/{id}/evidence', [App\Http\Controllers\ComplaintController::class, 'upload'])->whereNumber('id')->name('app.complaints.evidence.upload');
Route::get('/app/complaints/evidence/{fid}', [App\Http\Controllers\ComplaintController::class, 'download'])->whereNumber('fid')->name('app.complaints.evidence.download');

/*
| 10 Oct 2026 (Ejaz) — Leave Balance Report (Admin / HR) + the leave-year setting
| (Calendar Jan–Dec or Financial Apr–Mar) that every leave balance follows.
*/
Route::get('/app/leaves/report', [LeaveController::class, 'report'])->name('app.leaves.report');
Route::post('/app/leaves/year', [LeaveController::class, 'setLeaveYear'])->name('app.leaves.year');
