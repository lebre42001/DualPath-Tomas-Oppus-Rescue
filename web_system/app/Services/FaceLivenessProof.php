<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FaceLivenessProof
{
    public function assertValid(User $user, string $purpose, ?string $proof, ?UploadedFile $selfie): void
    {
        $this->check($this->query($user, $purpose, $proof)->first(), $selfie);
    }

    public function consume(User $user, string $purpose, ?string $proof, ?UploadedFile $selfie): void
    {
        DB::transaction(function () use ($user, $purpose, $proof, $selfie): void {
            $row = $this->query($user, $purpose, $proof)->lockForUpdate()->first();
            $this->check($row, $selfie);
            DB::table('face_liveness_challenges')->where('id', $row->id)->update(['consumed_at' => now()]);
        });
    }

    private function query(User $user, string $purpose, ?string $proof)
    {
        return DB::table('face_liveness_challenges')->where('user_id', $user->id)->where('purpose', $purpose)
            ->where('proof_hash', hash('sha256', $proof ?? ''))->whereNotNull('verified_at')
            ->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    private function check(?object $row, ?UploadedFile $selfie): void
    {
        if (! $row || ! $selfie || ! hash_equals((string) $row->selfie_hash, hash_file('sha256', $selfie->getRealPath()))) {
            throw ValidationException::withMessages(['liveness_proof' => 'Complete a new live face and blink check before continuing.']);
        }
    }
}
