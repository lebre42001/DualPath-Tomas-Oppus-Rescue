<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class LegalDocuments
{
    public static function registrationRules(): array
    {
        return [
            'terms_accepted' => ['required', 'accepted'],
            'privacy_acknowledged' => ['required', 'accepted'],
            'legal_version' => ['required', 'string', Rule::in([config('legal.version')])],
        ];
    }

    public static function registrationMessages(): array
    {
        return [
            'terms_accepted.required' => 'Agree to the Terms & Conditions and App License before creating an account.',
            'terms_accepted.accepted' => 'Agree to the Terms & Conditions and App License before creating an account.',
            'privacy_acknowledged.required' => 'Read and acknowledge the Privacy Policy before creating an account.',
            'privacy_acknowledged.accepted' => 'Read and acknowledge the Privacy Policy before creating an account.',
            'legal_version.required' => 'Reload this page and review the current documents before continuing.',
            'legal_version.in' => 'The documents have changed. Reload this page and review the current version.',
        ];
    }

    public static function acceptanceAttributes(): array
    {
        $now = now();

        return [
            'legal_documents_version' => config('legal.version'),
            'terms_accepted_at' => $now,
            'privacy_acknowledged_at' => $now,
        ];
    }
}
