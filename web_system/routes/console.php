<?php

use App\Services\AdminActivityRecorder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ai-learning:run')->hourly()->withoutOverlapping(40);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin-activity:backfill', function (AdminActivityRecorder $recorder) {
    $this->info($recorder->backfill().' recorded report actions imported into Admin System Log.');
})->purpose('Import existing report audit history without inventing past login or duty events');
