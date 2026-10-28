<?php

use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\IncidentReportApiController;
use App\Http\Controllers\Api\LoRaAlertIngestController;
use App\Http\Controllers\Api\LoRaMeshGatewayController;
use App\Http\Controllers\Api\ProfileApiController;
use App\Http\Controllers\Api\SettingsApiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CivilianPresenceController;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthApiController::class, 'register']);
    Route::post('/auth/login', [AuthApiController::class, 'login']);
    Route::post('/admin/auth/login', [AuthApiController::class, 'adminLogin']);
    Route::post('/responder/auth/login', [AuthApiController::class, 'responderLogin']);
    Route::post('/lora/alerts', LoRaAlertIngestController::class);
    Route::middleware('throttle:120,1')->prefix('lora')->group(function (): void {
        Route::get('/health', [LoRaMeshGatewayController::class, 'health']);
        Route::post('/heartbeat', [LoRaMeshGatewayController::class, 'heartbeat']);
        Route::post('/heartbeats', [LoRaMeshGatewayController::class, 'heartbeats']);
        Route::post('/probe', [LoRaMeshGatewayController::class, 'probe']);
        Route::get('/network-summary', [LoRaMeshGatewayController::class, 'networkSummary']);
        Route::get('/acks/pending', [LoRaMeshGatewayController::class, 'pendingAcknowledgment']);
        Route::post('/acks/{msgId}/delivered', [LoRaMeshGatewayController::class, 'acknowledgmentDelivered']);
    });

    Route::middleware('api.token')->group(function (): void {
        Route::post('/face-liveness/challenge', [\App\Http\Controllers\FaceLivenessController::class, 'challenge'])->middleware('throttle:12,1');
        Route::post('/face-liveness/verify', [\App\Http\Controllers\FaceLivenessController::class, 'verify'])->middleware('throttle:12,1');
        Route::post('/account/presence', CivilianPresenceController::class)->middleware('throttle:120,1');
        Route::get('/auth/me', [AuthApiController::class, 'me']);
        Route::post('/auth/logout', [AuthApiController::class, 'logout']);

        Route::get('/summary', DashboardApiController::class);
        Route::get('/reports', [IncidentReportApiController::class, 'index']);
        Route::post('/reports', [IncidentReportApiController::class, 'store']);
        Route::get('/reports/{incidentReport}', [IncidentReportApiController::class, 'show']);
        Route::get('/reports/{incidentReport}/evidence', [IncidentReportApiController::class, 'evidence'])->name('api.reports.evidence');
        Route::get('/reports/{incidentReport}/selfie', [IncidentReportApiController::class, 'selfie'])->name('api.reports.selfie');
        Route::get('/profile', [ProfileApiController::class, 'show']);
        Route::put('/profile', [ProfileApiController::class, 'update']);
        Route::get('/settings', [SettingsApiController::class, 'index']);
        Route::put('/settings', [SettingsApiController::class, 'update']);
    });
});
