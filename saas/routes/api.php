<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiController;
Route::post('/v1/installations/enroll',[ApiController::class,'enroll'])->middleware('throttle:10,1');
Route::post('/v1/billing/events',[ApiController::class,'billing'])->middleware('throttle:120,1');
Route::middleware(['installation','throttle:installation'])->prefix('v1')->group(function () {
    Route::get('/models',[ApiController::class,'models']);
    Route::post('/inference',[ApiController::class,'submit']);
    Route::get('/inference/{id}',[ApiController::class,'result'])->whereUuid('id');
});
