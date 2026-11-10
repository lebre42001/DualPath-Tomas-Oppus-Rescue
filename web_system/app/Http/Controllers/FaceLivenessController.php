<?php

namespace App\Http\Controllers;

use App\Services\FaceLivenessClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FaceLivenessController extends Controller
{
    public function challenge(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isCivilian(), 403);
        $data = $request->validate([
            'purpose' => ['required', 'in:account,report'],
            'submission_id' => ['required_if:purpose,report', 'nullable', 'uuid'],
        ]);
        if ($data['purpose'] === 'report') {
            abort_unless($request->user()->hasVerifiedAccount(), 403);
        }
        DB::table('face_liveness_challenges')->where('expires_at', '<', now()->subDay())->delete();
        $id = (string) Str::uuid();
        $isReport = $data['purpose'] === 'report';
        $blinks = $isReport ? 0 : random_int(1, 2);
        DB::table('face_liveness_challenges')->insert([
            'id' => $id, 'user_id' => $request->user()->id, 'purpose' => $data['purpose'],
            'submission_id' => $isReport ? strtolower($data['submission_id']) : null,
            'blink_count' => $blinks, 'created_at' => now(), 'expires_at' => now()->addMinutes(2),
        ]);

        return response()->json([
            'challenge_id' => $id, 'blink_count' => $blinks,
            'duration_ms' => $isReport ? 0 : ($blinks + 2) * 2000,
            'verification_mode' => $isReport ? 'face' : 'face_blink',
        ]);
    }

    public function verify(Request $request, FaceLivenessClient $client): JsonResponse
    {
        abort_unless($request->user()?->isCivilian(), 403);
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'], 'frames' => ['required', 'array', 'min:1', 'max:90'],
            'frames.*.jpeg' => ['required', 'string', 'max:90000'],
            'frames.*.ms' => ['required', 'integer', 'min:0', 'max:11000'],
        ]);
        $row = DB::table('face_liveness_challenges')->where('id', $data['challenge_id'])
            ->where('user_id', $request->user()->id)->whereNull('verified_at')->where('expires_at', '>', now())->first();
        if (! $row || Carbon::parse($row->created_at)->diffInMilliseconds(now()) + 500 < $data['frames'][array_key_last($data['frames'])]['ms']) {
            throw ValidationException::withMessages(['liveness_proof' => 'This live check has expired or is incomplete. Start a new check.']);
        }
        $isReport = $row->purpose === 'report';
        if (($isReport && count($data['frames']) > 10) || (! $isReport && count($data['frames']) < 30)) {
            throw ValidationException::withMessages(['liveness_proof' => 'The live capture is incomplete. Please retry.']);
        }
        $result = $client->verify($row->id, $isReport ? 0 : $row->blink_count, $data['frames'], $row->purpose);
        $proof = Str::random(64);
        $expiresIn = $isReport ? 86400 : 120;
        DB::transaction(function () use ($row, $proof, $result, $expiresIn): void {
            $locked = DB::table('face_liveness_challenges')->where('id', $row->id)->lockForUpdate()->first();
            if (! $locked || $locked->verified_at || Carbon::parse($locked->expires_at)->lte(now())) {
                throw ValidationException::withMessages(['liveness_proof' => 'Start a new live face check.']);
            }
            DB::table('face_liveness_challenges')->where('id', $row->id)->update([
                'selfie_hash' => $result['selfie_sha256'], 'proof_hash' => hash('sha256', $proof),
                'verified_at' => now(), 'expires_at' => now()->addSeconds($expiresIn),
            ]);
        });

        return response()->json(['proof' => $proof, 'expires_in' => $expiresIn]);
    }
}
