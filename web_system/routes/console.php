<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin-activity:backfill', function (\App\Services\AdminActivityRecorder $recorder) {
    $this->info($recorder->backfill().' recorded report actions imported into Admin System Log.');
})->purpose('Import existing report audit history without inventing past login or duty events');
