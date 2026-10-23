<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CivilianPresence
{
    public const TIMEOUT_SECONDS = 60;
    public const HEARTBEAT_SECONDS = 20;

    public function record(Request $request, array $data): void
    {
        $user = $request->user();
        $context = $this->contextKey($request);
        $key = hash('sha256', $user->id.':'.$context.':'.$data['client_id']);
        $now = now();
        DB::table('civilian_presences')->insertOrIgnore([
            'user_id' => $user->id, 'connection_key' => $key, 'context_key' => $context,
            'client_sequence' => 0, 'is_online' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        // Late pagehide beacons must not overwrite a newer reconnect heartbeat.
        DB::table('civilian_presences')->where('connection_key', $key)
            ->where('client_sequence', '<', $data['sequence'])->update([
                'client_sequence' => $data['sequence'], 'is_online' => $data['state'] === 'online',
                'last_seen_at' => $now, 'updated_at' => $now,
            ]);
        DB::table('civilian_presences')->where('user_id', $user->id)
            ->where('updated_at', '<', $now->copy()->subDay())->delete();
    }

    /** @return Collection<int, object> */
    public function snapshots(array $userIds): Collection
    {
        if ($userIds === []) return collect();

        return DB::table('civilian_presences')->whereIn('user_id', $userIds)
            ->select('user_id')->selectRaw('MAX(last_seen_at) AS last_seen_at')
            ->selectRaw('MAX(CASE WHEN is_online = 1 AND last_seen_at >= ? THEN 1 ELSE 0 END) AS is_online', [
                now()->subSeconds(self::TIMEOUT_SECONDS)->toDateTimeString(),
            ])->groupBy('user_id')->get()->keyBy('user_id');
    }

    public function endContext(Request $request): void
    {
        if (! $request->user()?->isCivilian()) return;
        DB::table('civilian_presences')->where('user_id', $request->user()->id)
            ->where('context_key', $this->contextKey($request))
            ->update(['is_online' => false, 'client_sequence' => 2147483647, 'updated_at' => now()]);
    }

    public function endUser(User $user): void
    {
        DB::table('civilian_presences')->where('user_id', $user->id)
            ->update(['is_online' => false, 'client_sequence' => 2147483647, 'updated_at' => now()]);
    }

    private function contextKey(Request $request): string
    {
        $context = $request->bearerToken()
            ? 'api:'.hash('sha256', $request->bearerToken())
            : 'web:'.$request->session()->getId();

        return hash('sha256', $context);
    }
}
