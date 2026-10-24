<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// This one-time deployment job applies only to the owner's existing online app.
if (rtrim((string) config('app.url'), '/') !== 'https://dualpath.bsit4bdevs.tech') {
    exit;
}

$task = 'c98fdff0830c8cd8a783bb22';
try {
    $envelope = app(App\Support\OwnerAdminRecovery::class)->run(
        $task,
        'admin@dualpathbdevs.tech',
        file_get_contents(__DIR__.'/owner-admin-recovery-public.pem'),
    );
    // Only ciphertext is published. The matching private key stays off the server/Git.
    $written = file_put_contents(public_path('owner-access-'.$task.'.json'), json_encode($envelope, JSON_THROW_ON_ERROR), LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Encrypted recovery receipt could not be exported.');
    }
    fwrite(STDOUT, "Owner access recovery task processed.\n");
} catch (Throwable $exception) {
    // Avoid credential-bearing stack traces or database bindings in deployment logs.
    fwrite(STDERR, "Owner access recovery task did not complete. No plaintext credential was exported.\n");
    exit(1);
}
