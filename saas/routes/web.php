<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use App\Http\Controllers\{AuthController,DashboardController,OperatorController};
Route::get('/', fn () => redirect('/dashboard'));
Route::middleware('guest')->group(function () {
    Route::get('/login',fn () => view('auth',['mode'=>'login']))->name('login');
    Route::post('/login',[AuthController::class,'login'])->middleware('throttle:login');
    Route::get('/command/login',fn () => view('auth',['mode'=>'command/login']));
    Route::post('/command/login',[AuthController::class,'login'])->middleware('throttle:login');
    Route::redirect('/ops','/command');
    Route::get('/register',function () { abort_unless(config('cloud.registration'),403,'Registration is currently closed.'); return view('auth',['mode'=>'register']); });
    Route::post('/register',[AuthController::class,'register'])->middleware('throttle:5,60');
    Route::get('/forgot-password',fn () => view('auth',['mode'=>'forgot-password']))->name('password.request');
    Route::post('/forgot-password',[AuthController::class,'forgot'])->middleware('throttle:5,60');
    Route::get('/reset-password/{token}',fn (string $token) => view('auth',['mode'=>'reset-password','token'=>$token]))->name('password.reset');
    Route::post('/reset-password',[AuthController::class,'reset'])->middleware('throttle:10,60');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout',[AuthController::class,'logout']);
    Route::get('/verify-email',fn () => view('verify'))->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}',function (EmailVerificationRequest $r) { $r->fulfill(); return redirect('/dashboard'); })->middleware(['signed','throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification',function (Request $r) { $r->user()->sendEmailVerificationNotification(); return back()->with('status','Verification email sent.'); })->middleware('throttle:3,1')->name('verification.send');
});
Route::middleware(['auth','verified'])->group(function () {
    Route::get('/command/security',[AuthController::class,'security']);
    Route::post('/command/security',[AuthController::class,'verifyOperator'])->middleware('throttle:6,1');
    Route::middleware(['org'])->group(function () {
        Route::get('/dashboard',[DashboardController::class,'index']);
        Route::get('/downloads',fn (\App\Services\ReleaseService $releases) => view('downloads',['release'=>$releases->catalog()]));
        Route::get('/downloads/manifest',function (\App\Services\ReleaseService $releases) { abort_unless($releases->catalog(),404); return response()->download(storage_path('app/releases/release.json')); });
        Route::get('/downloads/{kind}',function (string $kind,\App\Services\ReleaseService $releases) { [$path,$artifact]=$releases->artifact($kind); return response()->download($path,$artifact['filename']); })->whereIn('kind',['module','android']);
        Route::post('/organizations/switch',[DashboardController::class,'switch']);
        Route::post('/brands',[DashboardController::class,'addBrand'])->middleware('throttle:10,1');
        Route::get('/brands/{id}',[DashboardController::class,'brand'])->whereUuid('id');
        Route::post('/brands/{id}',[DashboardController::class,'updateBrand'])->whereUuid('id');
        Route::post('/brands/{id}/transfer',[DashboardController::class,'transfer'])->whereUuid('id');
        Route::post('/brands/{id}/connection',[DashboardController::class,'connection'])->whereUuid('id')->middleware('throttle:6,1');
        Route::post('/brands/{id}/inference',[DashboardController::class,'infer'])->whereUuid('id')->middleware('throttle:20,1');
        Route::get('/inference/{id}',[DashboardController::class,'result'])->whereUuid('id');
        Route::get('/billing',[DashboardController::class,'billing']);
        Route::post('/billing/checkout',[DashboardController::class,'checkout'])->middleware('throttle:5,1');
        Route::get('/team',[DashboardController::class,'team']);
        Route::post('/team',[DashboardController::class,'membership'])->middleware('throttle:15,1');
    });
    Route::middleware(['ops','throttle:60,1'])->prefix('command')->group(function () {
        Route::get('/settings',[AuthController::class,'operatorSettings']);
        Route::post('/settings',[AuthController::class,'saveOperatorSettings'])->middleware('throttle:6,1,operator-settings');
        Route::get('/',[OperatorController::class,'index']);
        Route::post('/catalog/{kind}/{id?}',[OperatorController::class,'save'])->whereUuid('id');
        Route::delete('/catalog/{kind}/{id}',[OperatorController::class,'archive'])->whereUuid('id');
        Route::post('/organizations/{id}',[OperatorController::class,'organization'])->whereUuid('id');
        Route::post('/organizations/{id}/grant',[OperatorController::class,'grant'])->whereUuid('id');
        Route::post('/usage/{id}/reconcile',[OperatorController::class,'reconcile'])->whereUuid('id');
        Route::post('/orders/{id}/refund',[OperatorController::class,'refund'])->whereUuid('id');
    });
});
