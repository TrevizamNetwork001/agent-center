<?php

use App\Http\Controllers\NocInventoryController;
use Illuminate\Support\Facades\Route;

// Administrative fixture generation only. No remote execution route.
Route::middleware(['auth', 'auth.session', 'password.changed'])->prefix('noc-inventory')->group(function () {
    Route::post('/{nocDevice}/analysis-lab', [NocInventoryController::class, 'demonstrate'])->whereUuid('nocDevice')->middleware('throttle:30,1')->name('noc.analysis-demo');
});
