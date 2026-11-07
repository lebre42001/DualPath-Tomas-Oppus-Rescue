<?php

namespace App\Services;

use App\Models\AdminActivity;
use App\Models\AdminDutyShift;
use App\Models\IncidentReportAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminActivityRecorder
{
    public function record(User $actor, string $category, string $action, string $label, string $summary, array $attributes = []): ?AdminActivity
    {
        if (! $actor->is_admin) {
            return null;
        }

        return AdminActivity::query()->create([
            ...$attributes, 'actor_reference' => $actor->id, 'actor_name' => $actor->name,
            'category' => $category, 'action' => $action, 'label' => $label,
            'summary' => $summary, 'occurred_at' => now(),
        ]);
    }

    public function reportAction(IncidentReportAudit $audit): void
    {
        // Older audit migrations can create entries before this table exists.
        // The idempotent deployment backfill imports those after all migrations.
        if (! \Illuminate\Support\Facades\Schema::hasTable('admin_activities')) return;
        $changes = [];
        if ($audit->status_before !== $audit->status_after && $audit->status_after) {
            $changes[] = 'Status: '.($audit->status_before ?: 'not recorded').' → '.$audit->status_after.'.';
        }
        if ($audit->severity_after && $audit->severity_before !== $audit->severity_after) {
            $changes[] = 'Severity: '.($audit->severity_before ?: 'not recorded').' → '.$audit->severity_after.'.';
        }
        AdminActivity::query()->firstOrCreate(['entry_key' => 'report-audit:'.$audit->id], [
            'actor_reference' => $audit->actor_id, 'actor_name' => $audit->actor_name ?: 'Not recorded',
            'category' => 'report', 'action' => 'report_'.$audit->action, 'label' => $audit->label,
            'summary' => implode(' ', $changes) ?: 'Recorded report action. Open report audit history for the original details.',
            'target_label' => $audit->report_code, 'incident_report_id' => $audit->incident_report_id,
            'source' => $audit->transmission_type === 'lora' ? 'lora' : 'online',
            'occurred_at' => $audit->occurred_at, 'imported' => (bool) $audit->imported,
        ]);
    }

    public function backfill(): int
    {
        $count = AdminActivity::query()->count();
        IncidentReportAudit::query()->orderBy('id')->chunkById(200, function ($entries): void {
            foreach ($entries as $entry) $this->reportAction($entry);
        });

        return AdminActivity::query()->count() - $count;
    }

    public function startDuty(User $actor): void
    {
        if (! $actor->is_admin) return;
        DB::transaction(function () use ($actor): void {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            if (AdminDutyShift::query()->where('active_user_id', $actor->id)->exists()) return;
            AdminDutyShift::query()->create([
                'active_user_id' => $actor->id, 'actor_reference' => $actor->id, 'actor_name' => $actor->name,
                'started_at' => now(), 'last_activity_at' => now(),
            ]);
            $this->record($actor, 'duty', 'duty_started', 'Started monitoring duty', 'The officer declared the start of monitoring duty.');
        });
    }

    public function endDuty(User $actor, bool $logout = false): void
    {
        if (! $actor->is_admin) return;
        DB::transaction(function () use ($actor, $logout): void {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $shift = AdminDutyShift::query()->where('active_user_id', $actor->id)->lockForUpdate()->first();
            if (! $shift) return;
            $shift->update(['active_user_id' => null, 'ended_at' => now(), 'last_activity_at' => now()]);
            $this->record($actor, 'duty', 'duty_ended', 'Ended monitoring duty', $logout ? 'Duty ended when the officer signed out.' : 'The officer declared the end of monitoring duty.');
        });
    }
}
