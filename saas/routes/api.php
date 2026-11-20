<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\ConnectorPreviewController;
use App\Http\Controllers\LegacyArchiveController;
Route::post('/v1/installations/enroll',[ApiController::class,'enroll'])->middleware('throttle:10,1');
Route::post('/v1/billing/events',[ApiController::class,'billing'])->middleware('throttle:120,1');
Route::middleware(['installation','throttle:installation'])->prefix('v1')->group(function () {
    Route::get('/connector-status',[ConnectorPreviewController::class,'status']);
    Route::post('/copilot/records',[ConnectorPreviewController::class,'records']);
    Route::get('/models',[ApiController::class,'models']);
    Route::post('/inference',[ApiController::class,'submit']);
    Route::get('/inference/{id}',[ApiController::class,'result'])->whereUuid('id');
    Route::post('/workflows',[\App\Http\Controllers\WorkflowController::class,'submit']);
    Route::post('/workflows/{id}/result',[\App\Http\Controllers\WorkflowController::class,'result'])->whereUuid('id');
    Route::prefix('legacy-archives/{archive}')->where(['archive'=>'[a-f0-9]{32}', 'sequence'=>'[1-9][0-9]{0,5}|1000000'])
        ->group(function () {
            Route::post('/start',[LegacyArchiveController::class,'start']);
            Route::post('/chunks/{sequence}',[LegacyArchiveController::class,'upload']);
            Route::post('/complete',[LegacyArchiveController::class,'complete']);
            Route::get('/chunks/{sequence}',[LegacyArchiveController::class,'download']);
        });
});
