<?php

namespace App\Services;

use App\Models\User;
use App\Support\IncidentReportIngestor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FaceLivenessProof
{
    public function assertValid(User $user, string $purpose, ?string $proof, ?UploadedFile $selfie, ?string $submissionId = null): void
    {
        DB::transaction(function () use ($user, $purpose, $proof, $selfie, $submissionId): void {
            $row = $this->query($user, $purpose, $proof)->lockForUpdate()->first();
            $this->check($row, $selfie, $purpose, $submissionId);
            // Pre-update report checks did not carry a submission ID. Bind those
            // once under a lock; all newly issued report checks are already bound.
            if ($purpose === 'report' && $row->submission_id === null) {
                DB::table('face_liveness_challenges')->where('id', $row->id)
                    ->update(['submission_id' => strtolower($submissionId)]);
            }
        });
    }

    public function consume(User $user, string $purpose, ?string $proof, ?UploadedFile $selfie): void
    {
        DB::transaction(function () use ($user, $purpose, $proof, $selfie): void {
            $row = $this->query($user, $purpose, $proof)->lockForUpdate()->first();
            $this->check($row, $selfie, $purpose);
            DB::table('face_liveness_challenges')->where('id', $row->id)->update(['consumed_at' => now()]);
        });
    }

    /** Consume only when the matching report has been committed successfully. */
    public function storeReport(User $user, ?string $proof, ?UploadedFile $selfie, string $submissionId, callable $persist): array
    {
        return DB::transaction(function () use ($user, $proof, $selfie, $submissionId, $persist): array {
            $ingestor = app(IncidentReportIngestor::class);
            // The same proof cannot concurrently create a second report. If the
            // first request committed while this one waited, return its receipt.
            $row = $this->query($user, 'report', $proof)->lockForUpdate()->first();
            if ($existing = $ingestor->existing($ingestor->onlineKey($user, $submissionId))) {
                return ['report' => $existing, 'created' => false];
            }
            $this->check($row, $selfie, 'report', $submissionId);
            $result = $persist();
            if ($result['created']) {
                DB::table('face_liveness_challenges')->where('id', $row->id)->update([
                    'submission_id' => strtolower($submissionId), 'consumed_at' => now(),
                ]);
            }

            return $result;
        });
    }

    private function query(User $user, string $purpose, ?string $proof)
    {
        return DB::table('face_liveness_challenges')->where('user_id', $user->id)->where('purpose', $purpose)
            ->where('proof_hash', hash('sha256', $proof ?? ''))->whereNotNull('verified_at')
            ->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    private function check(?object $row, ?UploadedFile $selfie, string $purpose, ?string $submissionId = null): void
    {
        if (! $row || ! $selfie || ! hash_equals((string) $row->selfie_hash, hash_file('sha256', $selfie->getRealPath()))
            || ($purpose === 'report' && (! $submissionId || ($row->submission_id !== null
                && ! hash_equals(strtolower($row->submission_id), strtolower($submissionId)))))) {
            throw ValidationException::withMessages(['liveness_proof' => $purpose === 'report'
                ? 'Complete a face check for this report before continuing.'
                : 'Complete a new live face and blink check before continuing.']);
        }
    }
}
