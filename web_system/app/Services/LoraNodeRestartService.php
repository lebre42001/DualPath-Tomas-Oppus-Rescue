<?php

namespace App\Services;

use App\Models\AdminActivity;
use App\Models\LoraNodeRestartCommand;
use App\Models\LoraNodeState;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LoraNodeRestartService
{
    public const PROTOCOL = 'signed-restart-v1';

    public const EXPIRY_SECONDS = 300;

    public const COOLDOWN_SECONDS = 120;

    public const CAPABILITY_MAX_AGE_SECONDS = 120;

    public function registered(string $nodeId): bool
    {
        return preg_match('/^NODE_(?:[1-9]|10)$/D', $nodeId) === 1
            && isset(config('lora_nodes', [])[$nodeId]);
    }

    public function capable(?LoraNodeState $state): bool
    {
        return $state !== null && $state->restart_protocol === self::PROTOCOL
            && $state->radio_auth === 'hmac-sha256-v2'
            && preg_match('/^[a-f0-9]{1,16}$/D', (string) $state->guard_boot) === 1
            && $state->guard_reported_at?->greaterThanOrEqualTo(now()->subSeconds(self::CAPABILITY_MAX_AGE_SECONDS));
    }

    public function disabledReason(?LoraNodeState $state, ?LoraNodeState $gateway, ?LoraNodeRestartCommand $command = null): ?string
    {
        if ($command?->active_node_id !== null && $command?->expires_at->greaterThan(now())) {
            return 'A restart is already pending. Wait for confirmation or expiry.';
        }
        if ($command?->requested_at->greaterThan(now()->subSeconds(self::COOLDOWN_SECONDS))) {
            return 'Wait two minutes between restart requests for this node.';
        }
        if ($state?->restart_protocol !== self::PROTOCOL) {
            return 'Upload the updated restart firmware to this node first.';
        }
        if (! $this->capable($state)) {
            return 'No recent authenticated node heartbeat. Power on the node and restore its radio link.';
        }
        if ($gateway?->restart_protocol !== self::PROTOCOL) {
            return 'Upload the updated restart firmware to NODE_1 first.';
        }
        if (! $this->capable($gateway)) {
            return 'The gateway has no recent authenticated heartbeat. Restore its power or internet connection.';
        }

        return null;
    }

    public function expireDue(): void
    {
        LoraNodeRestartCommand::query()->whereNotNull('active_node_id')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'active_node_id' => null, 'updated_at' => now()]);
    }

    /** @return Collection<string, LoraNodeRestartCommand> */
    public function latestByNode(): Collection
    {
        $this->expireDue();

        return LoraNodeRestartCommand::query()->whereIn('id', LoraNodeRestartCommand::query()
            ->selectRaw('MAX(id)')->groupBy('node_id'))->get()->keyBy('node_id');
    }

    /** @return array{command: LoraNodeRestartCommand, created: bool} */
    public function request(User $actor, string $nodeId): array
    {
        abort_unless($actor->is_admin, 403);
        abort_unless($this->registered($nodeId), 404, 'Unknown registered LoRa node.');

        return DB::transaction(function () use ($actor, $nodeId): array {
            // Existing telemetry provides a durable per-node row lock. Restart
            // never creates fake node telemetry or pretends a heartbeat arrived.
            $state = LoraNodeState::query()->where('node_id', $nodeId)->lockForUpdate()->first();
            $this->expireDue();
            $active = LoraNodeRestartCommand::query()->where('active_node_id', $nodeId)->first();
            if ($active !== null) {
                return ['command' => $active, 'created' => false];
            }

            $latest = LoraNodeRestartCommand::query()->where('node_id', $nodeId)->latest('id')->first();
            abort_if($latest?->requested_at->greaterThan(now()->subSeconds(self::COOLDOWN_SECONDS)), 429,
                'Please wait two minutes between restart requests for this node.');
            abort_unless($this->capable($state), 409,
                'Restart requires the updated node firmware and a recent authenticated heartbeat.');
            $gateway = $nodeId === 'NODE_1' ? $state : LoraNodeState::query()->where('node_id', 'NODE_1')->first();
            abort_unless($this->capable($gateway), 409,
                'The gateway must be reachable with the updated restart firmware.');

            $command = LoraNodeRestartCommand::query()->create([
                'node_id' => $nodeId, 'active_node_id' => $nodeId, 'status' => 'queued',
                'actor_reference' => $actor->id, 'actor_name' => $actor->name,
                'expected_boot' => $state->guard_boot, 'requested_at' => now(),
                'expires_at' => now()->addSeconds(self::EXPIRY_SECONDS),
            ]);
            // Firmware's persistent anti-replay high-water mark is uint32.
            abort_if($command->id > 4294967295, 503, 'Restart command sequence is exhausted.');
            $this->record($command, 'requested');

            return ['command' => $command, 'created' => true];
        });
    }

    public function pending(): ?LoraNodeRestartCommand
    {
        $this->expireDue();

        // Polling is not delivery confirmation. Active commands are returned
        // repeatedly until their authenticated acceptance or expiry is recorded.
        return DB::transaction(function (): ?LoraNodeRestartCommand {
            $command = LoraNodeRestartCommand::query()->whereIn('status', ['queued', 'sent'])
                ->whereNotNull('active_node_id')->where('expires_at', '>', now())
                ->orderByRaw('CASE WHEN last_offered_at IS NULL THEN 0 ELSE 1 END')
                ->oldest('last_offered_at')->oldest('id')->lockForUpdate()->first();
            // Offer commands in rotation so an unreachable target cannot starve
            // every other node. This timestamp never claims radio delivery.
            $command?->forceFill(['last_offered_at' => now()])->save();

            return $command;
        });
    }

    public function result(int $commandId, array $data): LoraNodeRestartCommand
    {
        return DB::transaction(function () use ($commandId, $data): LoraNodeRestartCommand {
            $command = LoraNodeRestartCommand::query()->lockForUpdate()->findOrFail($commandId);
            abort_unless($command->node_id === $data['node_id'], 422, 'The receipt target does not match this command.');
            if ($command->active_node_id !== null && $command->expires_at->lessThanOrEqualTo(now())) {
                $command->forceFill(['status' => 'expired', 'active_node_id' => null])->save();
            }
            $status = $data['status'];
            if ($status === 'sent' && in_array($command->status, ['accepted', 'restarted'], true)) {
                // An HTTP response can be lost after a receipt is stored. A
                // replay of an earlier phase never regresses the command.
                return $command;
            }
            if ($command->status === 'restarted') {
                // A retried receipt must describe the same actual boot, never
                // overwrite a confirmed restart with another boot or failure.
                $sameReceipt = ($status === 'restarted' && $command->restarted_boot === ($data['boot_id'] ?? null))
                    || ($status === 'accepted' && $command->accepted_boot === ($data['boot_id'] ?? null));
                abort_unless($sameReceipt, 409,
                    'A different restart result has already been recorded.');

                return $command;
            }

            if ($status === 'sent') {
                abort_unless(in_array($command->status, ['queued', 'sent'], true), 409, 'This command is no longer awaiting delivery.');
                $command->forceFill(['status' => 'sent', 'sent_at' => $command->sent_at ?? now()])->save();
            } elseif ($status === 'accepted') {
                abort_unless(in_array($command->status, ['queued', 'sent', 'accepted', 'expired'], true), 409, 'This command cannot be accepted.');
                abort_unless(hash_equals($command->expected_boot, $data['boot_id']), 409,
                    'This command belongs to a different pre-restart boot.');
                // A late signed receipt is evidence only: it must not reactivate
                // an expired request or make the gateway dispatch it again.
                $command->forceFill([
                    'status' => $command->status === 'expired' ? 'expired' : 'accepted',
                    'accepted_boot' => $data['boot_id'], 'accepted_at' => $command->accepted_at ?? now(),
                ])->save();
                $this->record($command, 'accepted');
            } elseif ($status === 'restarted') {
                abort_unless($command->accepted_at !== null && $command->accepted_boot !== null, 409,
                    'A signed acceptance receipt is required before post-boot confirmation.');
                abort_unless(in_array($command->status, ['accepted', 'expired'], true), 409, 'This command cannot be confirmed.');
                abort_if(hash_equals($command->accepted_boot, $data['boot_id']), 409,
                    'A new boot identifier is required to confirm a restart.');
                $command->forceFill([
                    'status' => 'restarted', 'active_node_id' => null,
                    'restarted_boot' => $data['boot_id'], 'restarted_at' => now(),
                ])->save();
                $this->record($command, 'restarted');
            } else {
                abort_unless(in_array($command->status, ['queued', 'sent', 'expired', 'failed'], true) && $command->accepted_at === null, 409,
                    'An accepted restart requires post-boot confirmation or expires without confirmation.');
                if ($command->status !== 'failed') {
                    $command->forceFill([
                        'status' => 'failed', 'active_node_id' => null,
                        'failure_reason' => $data['reason'], 'failed_at' => now(),
                    ])->save();
                    $this->record($command, 'failed');
                }
            }

            return $command;
        });
    }

    public function summary(LoraNodeRestartCommand $command): array
    {
        $label = match ($command->status) {
            'queued' => 'Queued', 'sent' => 'Sent', 'accepted' => 'Restarting',
            'restarted' => 'Restart confirmed', 'expired' => 'Expired', default => 'Failed',
        };
        $detail = match ($command->status) {
            'queued' => 'Waiting for the gateway to deliver the restart request.',
            'sent' => 'The gateway sent the request; target acceptance is not yet confirmed.',
            'accepted' => 'The node accepted the request. Waiting for its signed post-boot confirmation.',
            'restarted' => 'A signed post-boot receipt confirmed this specific restart. Check the current connection status separately.',
            'expired' => 'The request expired without a post-boot confirmation. A restart has not been confirmed.',
            default => 'Restart could not complete: '.str_replace('_', ' ', (string) $command->failure_reason).'. Pending reports are retained.',
        };

        return [
            'id' => (string) $command->id, 'node_id' => $command->node_id, 'status' => $command->status,
            'label' => $label, 'detail' => $detail,
            'requested_by' => $command->actor_name, 'requested_at' => $command->requested_at->toIso8601String(),
            'expires_at' => $command->expires_at->toIso8601String(),
            'sent_at' => $command->sent_at?->toIso8601String(),
            'accepted_at' => $command->accepted_at?->toIso8601String(),
            'restarted_at' => $command->restarted_at?->toIso8601String(),
            'failure_reason' => $command->failure_reason,
        ];
    }

    private function record(LoraNodeRestartCommand $command, string $event): void
    {
        $label = match ($event) {
            'requested' => 'Requested node restart', 'accepted' => 'Node accepted restart',
            'restarted' => 'Node restart confirmed', default => 'Node restart failed',
        };
        $summary = match ($event) {
            'requested' => 'The administrator queued a targeted restart. Pending reports are retained; delivery is not yet confirmed.',
            'accepted' => 'The gateway reported the target\'s authenticated acceptance. A post-boot confirmation is still required.',
            'restarted' => 'The gateway verified the target\'s signed post-boot receipt for this command with a different boot identifier.',
            default => 'The gateway reported that the restart could not complete: '.str_replace('_', ' ', (string) $command->failure_reason).'.',
        };
        AdminActivity::query()->firstOrCreate(['entry_key' => 'node-restart:'.$command->id.':'.$event], [
            'actor_reference' => $command->actor_reference, 'actor_name' => $command->actor_name,
            'category' => 'system', 'action' => 'node_restart_'.$event,
            'label' => $label, 'summary' => $summary, 'target_label' => $command->node_id,
            'source' => 'lora', 'context' => ['command_id' => (string) $command->id, 'provenance' => $event === 'requested' ? 'authenticated_admin' : 'authenticated_gateway_receipt'],
            'occurred_at' => now(),
        ]);
    }
}
