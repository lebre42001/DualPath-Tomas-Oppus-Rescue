<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class FaceLivenessClient
{
    public function verify(string $challengeId, int $blinks, array $frames): array
    {
        $body = json_encode(['challenge_id' => $challengeId, 'required_blinks' => $blinks, 'frames' => $frames], JSON_THROW_ON_ERROR);
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(30)
                ->withHeaders(['X-Liveness-Signature' => hash_hmac('sha256', $body, (string) config('app.key'))])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('services.face_liveness.url'), '/').'/liveness/verify');
            if (! $response->successful()) throw new \RuntimeException('Liveness unavailable');
            $result = $response->json();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['liveness_proof' => 'Live face check is temporarily unavailable. Please retry.']);
        }
        if (($result['accepted'] ?? false) !== true) {
            $message = match ($result['code'] ?? '') {
                'multiple_faces' => 'Only one person should be visible during the blink check.',
                'no_face' => 'Keep one face clearly visible in good lighting, then try again.',
                'face_moved' => 'Keep your face inside the guide and hold the camera steady.',
                'blink_missing' => 'Blink slowly as requested, then keep your eyes open until the check finishes.',
                default => 'The live capture was interrupted. Keep this page open and try again.',
            };
            throw ValidationException::withMessages(['liveness_proof' => $message]);
        }
        $last = base64_decode($frames[array_key_last($frames)]['jpeg'], true);
        if ($last === false || ! hash_equals(hash('sha256', $last), (string) ($result['selfie_sha256'] ?? ''))
            || (int) ($result['blink_count'] ?? 0) < $blinks) {
            throw ValidationException::withMessages(['liveness_proof' => 'The live capture could not be verified. Please retry.']);
        }
        return $result;
    }
}
