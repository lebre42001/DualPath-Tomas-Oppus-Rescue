<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\WelcomeController;
use App\Http\Controllers\Web\AccountVerificationController;
use App\Http\Controllers\Web\AdminDashboardController;
use App\Http\Controllers\Web\AiTextTestController;
use App\Http\Controllers\Web\CivilianAccountController;
use App\Http\Controllers\Web\CivilianReportController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\IncidentReportController;
use App\Http\Controllers\Web\OnlineReportController;
use App\Http\Controllers\Web\IncidentReportExportController;
use App\Http\Controllers\Web\IncidentSeverityReviewController;
use App\Http\Controllers\Web\IncidentTextSuggestionController;
use App\Http\Controllers\Web\TextAiStatusController;
use App\Http\Controllers\Web\MonitoringDashboardController;
use App\Http\Controllers\Web\LoraNodeLocationController;
use App\Http\Controllers\Web\LoRaReportController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\SettingsController;
use App\Support\SystemVersion;
use App\Http\Controllers\CivilianPresenceController;
use App\Http\Controllers\LegalDocumentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/privacy-policy', LegalDocumentController::class)->defaults('document', 'privacy')->name('legal.privacy');
Route::get('/terms-and-conditions', LegalDocumentController::class)->defaults('document', 'terms')->name('legal.terms');
Route::get('/app-license', LegalDocumentController::class)->defaults('document', 'license')->name('legal.license');

Route::get('/system/version', static function (SystemVersion $systemVersion) {
    return response()->json([
        'version' => $systemVersion->current(),
        'generated_at' => now()->toIso8601String(),
    ], 200, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->name('system.version');

Route::get('/system/ai-status', TextAiStatusController::class)->middleware('throttle:30,1')->name('system.ai-status');

Route::get('/download/android', static function () {
    return redirect()->away(config('android-app.download_url'))
        ->withHeaders(['Cache-Control' => 'no-store']);
})->name('app.android.download');

Route::get('/admin', static function (Request $request) {
    return redirect()->route($request->user() ? 'admin.dashboard' : 'admin.login');
})->name('admin.index');

Route::middleware('guest')->group(function (): void {
    Route::get('/', WelcomeController::class)->name('welcome');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::post('/auth/google', [GoogleAuthController::class, 'authorize'])->middleware('throttle:12,1')->name('auth.google.authorize');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
    Route::get('/admin/login', [AuthenticatedSessionController::class, 'createAdmin'])->name('admin.login');
    Route::post('/admin/login', [AuthenticatedSessionController::class, 'storeAdmin'])->name('admin.login.store');
    Route::get('/responder/login', [AuthenticatedSessionController::class, 'createResponder'])->name('responder.login');
    Route::post('/responder/login', [AuthenticatedSessionController::class, 'storeResponder'])->name('responder.login.store');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

Route::middleware(['auth', 'account.active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/account/presence', CivilianPresenceController::class)->middleware('throttle:120,1')->name('account.presence');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::redirect('/home', '/dashboard');
    Route::get('/account/verify', [AccountVerificationController::class, 'create'])->name('account.verify');
    Route::post('/account/verify', [AccountVerificationController::class, 'store'])->name('account.verify.store');
    Route::post('/face-liveness/challenge', [\App\Http\Controllers\FaceLivenessController::class, 'challenge'])->middleware('throttle:12,1')->name('face-liveness.challenge');
    Route::post('/face-liveness/verify', [\App\Http\Controllers\FaceLivenessController::class, 'verify'])->middleware('throttle:12,1')->name('face-liveness.verify');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
        Route::get('/report-audit', [AdminDashboardController::class, 'history'])->name('report-audit');
        Route::get('/node-locations', [LoraNodeLocationController::class, 'index'])->name('node-locations.index');
        Route::get('/node-locations/points', [LoraNodeLocationController::class, 'points'])->name('node-locations.points');
        Route::put('/node-locations/{nodeId}', [LoraNodeLocationController::class, 'update'])->name('node-locations.update');
        Route::get('/text-ai-test', [AiTextTestController::class, 'index'])->name('text-ai-test');
        Route::post('/text-ai-test', [AiTextTestController::class, 'predict'])->name('text-ai-test.predict');
    });

    Route::middleware('responder')->group(function (): void {
        Route::get('/lora-reports', [LoRaReportController::class, 'index'])->name('lora-reports.index');
        Route::get('/lora-reports/{incidentReport}/print', [LoRaReportController::class, 'print'])->name('lora-reports.print');
        Route::post('/lora-reports/{incidentReport}/verification', [LoRaReportController::class, 'verify'])->name('lora-reports.verification');
        Route::get('/monitoring', MonitoringDashboardController::class)->name('monitoring');
        Route::get('/monitoring/lora-mesh', [MonitoringDashboardController::class, 'loraMesh'])->name('monitoring.lora-mesh');
        Route::get('/monitoring/lora-mesh/nodes', [MonitoringDashboardController::class, 'loraMeshNodes'])->name('monitoring.lora-mesh.nodes');

        Route::prefix('civilian-accounts')->name('civilian-accounts.')->group(function (): void {
            Route::get('/', [CivilianAccountController::class, 'index'])->name('index');
            Route::get('/all', [CivilianAccountController::class, 'all'])->name('all');
            Route::get('/presence', [CivilianAccountController::class, 'presence'])->name('presence');
            Route::get('/{civilianAccount}/reports', [CivilianReportController::class, 'index'])->name('reports');
            Route::get('/{civilianAccount}/reports/print', [CivilianReportController::class, 'print'])->name('reports.print');
            Route::get('/{civilianAccount}/reports/{incidentReport}/details', [CivilianReportController::class, 'details'])->name('reports.details');
            Route::get('/{civilianAccount}/verification-image', [CivilianAccountController::class, 'verificationImage'])->name('verification-image');
            Route::patch('/{civilianAccount}/block', [CivilianAccountController::class, 'block'])->name('block');
            Route::delete('/{civilianAccount}', [CivilianAccountController::class, 'destroy'])->name('destroy');
        });
    });

    Route::middleware('responder')->prefix('reports')->name('reports.')->group(function (): void {
        Route::get('/export/preview', [IncidentReportExportController::class, 'preview'])->name('export.preview');
        Route::get('/export', [IncidentReportExportController::class, 'download'])->name('export');
        Route::get('/all', [OnlineReportController::class, 'index'])->name('all');
        Route::get('/{incidentReport}/details', [OnlineReportController::class, 'details'])->name('online-details');
        Route::get('/{incidentReport}/print', [OnlineReportController::class, 'print'])->name('print');
    });

    Route::prefix('reports')->name('reports.')->group(function (): void {
        Route::get('/', [IncidentReportController::class, 'index'])->name('index');
        Route::get('/create', [IncidentReportController::class, 'create'])->name('create');
        Route::post('/', [IncidentReportController::class, 'store'])->name('store');
        Route::get('/{incidentReport}/evidence', [IncidentReportController::class, 'evidence'])->name('evidence');
        Route::get('/{incidentReport}/selfie', [IncidentReportController::class, 'selfie'])->name('selfie');
        Route::get('/{incidentReport}', [IncidentReportController::class, 'show'])->name('show');
        Route::get('/{incidentReport}/success', [IncidentReportController::class, 'success'])->name('success');

        Route::middleware('responder')->group(function (): void {
            Route::delete('/', [IncidentReportController::class, 'destroyAll'])->name('destroy-all');
            Route::post('/{incidentReport}/coordination', [IncidentReportController::class, 'updateCoordination'])->name('coordination');
            Route::post('/{incidentReport}/severity-review', IncidentSeverityReviewController::class)->name('severity-review');
            Route::post('/{incidentReport}/ai-suggestion', IncidentTextSuggestionController::class)->name('ai-suggestion');
            Route::delete('/{incidentReport}', [IncidentReportController::class, 'destroy'])->name('destroy');
            Route::get('/{incidentReport}/ai-severity', [IncidentReportController::class, 'severity'])->name('severity');
            Route::get('/{incidentReport}/transmissions', [IncidentReportController::class, 'transmissions'])->name('transmissions');
        });
    });

    Route::get('/profile/photo', [ProfileController::class, 'photo'])->name('profile.photo');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/readiness', [SettingsController::class, 'readiness'])->name('settings.readiness');
    Route::post('/settings/preferences', [SettingsController::class, 'update'])->name('settings.update');
});
