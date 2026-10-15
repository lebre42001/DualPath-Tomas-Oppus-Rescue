<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->boolean('reported_fatality_flag')->default(false)->index();
        });

        DB::table('incident_reports')
            ->select(['id', 'description'])
            ->orderBy('id')
            ->chunkById(500, function ($reports): void {
                $matchingIds = $reports
                    ->filter(fn ($report): bool => is_string($report->description)
                        && preg_match('/(?<![\p{L}\p{N}])patay(?![\p{L}\p{N}])/iu', $report->description) === 1)
                    ->pluck('id');

                if ($matchingIds->isNotEmpty()) {
                    DB::table('incident_reports')->whereIn('id', $matchingIds)->update(['reported_fatality_flag' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->dropIndex(['reported_fatality_flag']);
            $table->dropColumn('reported_fatality_flag');
        });
    }
};
