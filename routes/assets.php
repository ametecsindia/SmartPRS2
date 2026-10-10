<?php

use App\Http\Controllers\AssetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ASSET STOCK + ASSET ISSUE & RETURNS (30 Sep 2026, Ejaz)
|--------------------------------------------------------------------------
| Mounted from bootstrap/app.php with the same stack as the /app group in
| routes/web.php — declared here so web.php is never rewritten.
|
|   GET  /app/asset-mgmt/stock               units + summary
|   POST /app/asset-mgmt/stock               add (bulk, auto IDs) / edit
|   POST /app/asset-mgmt/stock/{id}/delete   never-issued units only
|   GET  /app/asset-mgmt/employees           active employees for the picker
|   GET  /app/asset-mgmt/issues              ?scope=open|returned|all&emp=
|   POST /app/asset-mgmt/issue               one employee, SEVERAL items
|   POST /app/asset-mgmt/return              {ids[], returned_on, condition, remarks}
|   GET  /app/asset-mgmt/mine                any login — ONLY the caller's own items
|   GET  /app/asset-mgmt/export              ?scope=open|returned|all|stock&format=csv|pdf
*/

Route::get('/app/asset-mgmt/stock', [AssetController::class, 'stock'])->name('app.assetmgmt.stock');
Route::post('/app/asset-mgmt/stock', [AssetController::class, 'saveStock'])->name('app.assetmgmt.stock.save');
Route::post('/app/asset-mgmt/stock/{id}/delete', [AssetController::class, 'deleteStock'])->whereNumber('id')->name('app.assetmgmt.stock.delete');
Route::get('/app/asset-mgmt/employees', [AssetController::class, 'employees'])->name('app.assetmgmt.employees');
Route::get('/app/asset-mgmt/issues', [AssetController::class, 'issueList'])->name('app.assetmgmt.issues');
Route::post('/app/asset-mgmt/issue', [AssetController::class, 'issue'])->name('app.assetmgmt.issue');
Route::post('/app/asset-mgmt/return', [AssetController::class, 'returnItems'])->name('app.assetmgmt.return');
Route::get('/app/asset-mgmt/export', [AssetController::class, 'export'])->name('app.assetmgmt.export');
Route::get('/app/asset-mgmt/mine', [AssetController::class, 'mine'])->name('app.assetmgmt.mine');
