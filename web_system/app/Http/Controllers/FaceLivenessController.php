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
        $data = $request->validate(['purpose' => ['required', 'in:account,report']]);
        if ($data['purpose'] === 'report') abort_unless($request->user()->hasVerifiedAccount(), 403);
        DB::table('face_liveness_challenges')->where('expires_at', '<', now()->subDay())->delete();
        $id = (string) Str::uuid();
        $blinks = random_int(1, 2);
        DB::table('face_liveness_challenges')->insert([
            'id' => $id, 'user_id' => $request->user()->id, 'purpose' => $data['purpose'],
            'blink_count' => $blinks, 'created_at' => now(), 'expires_at' => now()->addMinutes(2),
        ]);
        return response()->json(['challenge_id' => $id, 'blink_count' => $blinks, 'duration_ms' => ($blinks+2)*2000]);
    }

    public function verify(Request $request, FaceLivenessClient $client): JsonResponse
    {
        abort_unless($request->user()?->isCivilian(), 403);
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'], 'frames' => ['required', 'array', 'min:30', 'max:90'],
            'frames.*.jpeg' => ['required', 'string', 'max:90000'],
            'frames.*.ms' => ['required', 'integer', 'min:0', 'max:11000'],
        ]);
        $row = DB::table('face_liveness_challenges')->where('id', $data['challenge_id'])
            ->where('user_id', $request->user()->id)->whereNull('verified_at')->where('expires_at', '>', now())->first();
        if (! $row || Carbon::parse($row->created_at)->diffInMilliseconds(now())+500 < $data['frames'][array_key_last($data['frames'])]['ms']) {
            throw ValidationException::withMessages(['liveness_proof' => 'This live check has expired or is incomplete. Start a new check.']);
        }
        $result = $client->verify($row->id, $row->blink_count, $data['frames']);
        $proof = Str::random(64);
        DB::transaction(function () use ($row, $proof, $result): void {
            $locked = DB::table('face_liveness_challenges')->where('id', $row->id)->lockForUpdate()->first();
            if ($locked->verified_at || Carbon::parse($locked->expires_at)->lte(now())) {
                throw ValidationException::withMessages(['liveness_proof' => 'Start a new live face check.']);
            }
            DB::table('face_liveness_challenges')->where('id', $row->id)->update([
                'selfie_hash' => $result['selfie_sha256'], 'proof_hash' => hash('sha256', $proof),
                'verified_at' => now(), 'expires_at' => now()->addMinutes(2),
            ]);
        });
        return response()->json(['proof' => $proof, 'expires_in' => 120]);
    }
}
