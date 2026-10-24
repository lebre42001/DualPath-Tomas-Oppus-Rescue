<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Temporary CLI deployment task for the owner's explicitly requested recovery. */
class OwnerAdminRecovery
{
    public function run(string $task, string $email, string $publicKey): array
    {
        // Keep the encrypted receipt and password change on the same connection.
        $receipts = new Repository(new DatabaseStore(DB::connection(), 'cache', 'owner-admin-recovery:'));

        return $receipts->lock($task, 60)->block(10, function () use ($receipts, $task, $email, $publicKey): array {
            return DB::transaction(function () use ($receipts, $task, $email, $publicKey): array {
                if ($existing = $receipts->get($task)) {
                    return $existing;
                }

                $admin = User::query()->where('email', $email)->lockForUpdate()->first();
                if (! $admin || ! $admin->is_admin) {
                    return $this->seal($task, [
                        'status' => 'target_not_found',
                        'requested_email' => $email,
                        'admins' => User::query()->where('is_admin', true)->get(['name', 'email'])->toArray(),
                    ], $publicKey);
                }

                $password = 'Rescue-'.Str::random(18).'!7';
                // Encrypt before any credential mutation; plaintext never leaves memory.
                $envelope = $this->seal($task, [
                    'status' => 'recovered',
                    'email' => $admin->email,
                    'name' => $admin->name,
                    'password' => $password,
                    'recovered_at' => now()->toIso8601String(),
                ], $publicKey);

                $admin->forceFill(['password' => $password, 'remember_token' => null, 'api_token' => null])->save();
                DB::table('sessions')->where('user_id', $admin->id)->delete();
                $receipts->forever($task, $envelope);

                return $envelope;
            });
        });
    }

    private function seal(string $task, array $payload, string $publicKey): array
    {
        $key = random_bytes(32);
        $nonce = random_bytes(12);
        $ciphertext = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $task);
        if ($ciphertext === false || ! openssl_public_encrypt($key, $wrappedKey, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('Recovery payload encryption failed; no account change was committed.');
        }

        return [
            'version' => 1,
            'task' => $task,
            'algorithm' => 'RSA-OAEP+AES-256-GCM',
            'key' => base64_encode($wrappedKey),
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }
}
