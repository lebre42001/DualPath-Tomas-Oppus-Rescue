<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Workflow fixtures explicitly use a proof that has already passed the separate
    // live-check service tests. Missing-proof security tests never call this helper.
    protected function verifiedSelfie(\Illuminate\Http\UploadedFile $file, ?\App\Models\User $user = null, string $purpose = 'report', string $field = 'selfie'): array
    {
        if (! $user) {
            $authorization = $this->defaultHeaders['Authorization'] ?? '';
            $user = str_starts_with($authorization, 'Bearer ')
                ? \App\Models\User::where('api_token', hash('sha256', substr($authorization, 7)))->first()
                : \Illuminate\Support\Facades\Auth::user();
        }
        if (! $user) throw new \LogicException('Pass the fixture user when preparing liveness before authentication.');
        if (! $user->isCivilian()) return [$field => $file];
        $proof = \Illuminate\Support\Str::random(64);
        \Illuminate\Support\Facades\DB::table('face_liveness_challenges')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'purpose' => $purpose,
            'blink_count' => 2, 'selfie_hash' => hash_file('sha256', $file->getRealPath()),
            'proof_hash' => hash('sha256', $proof), 'verified_at' => now(), 'created_at' => now()->subSeconds(10),
            'expires_at' => now()->addMinutes(2),
        ]);
        return [$field => $file, 'liveness_proof' => $proof];
    }
}
