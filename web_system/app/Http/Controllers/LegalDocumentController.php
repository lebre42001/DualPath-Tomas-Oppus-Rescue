<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class LegalDocumentController extends Controller
{
    public function __invoke(string $document): View
    {
        $documents = [
            'privacy' => ['Privacy Policy', 'Understand what you share and how it is used.'],
            'terms' => ['Terms & Conditions', 'Use AksyRes responsibly and understand its limits.'],
            'license' => ['App License (EULA)', 'Permission to use the website, PWA, and Android launcher.'],
        ];
        abort_unless(isset($documents[$document]), 404);

        return view('legal.show', [
            'document' => $document,
            'documentTitle' => $documents[$document][0],
            'documentSummary' => $documents[$document][1],
        ]);
    }
}
