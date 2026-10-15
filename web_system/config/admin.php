<?php

return [
    'seed' => [
        'email' => env('ADMIN_SEED_EMAIL'),
        'password' => env('ADMIN_SEED_PASSWORD'),
        'name' => env('ADMIN_SEED_NAME', 'Admin Receiver'),
        'station' => env('ADMIN_SEED_STATION', 'Bontoc Command Center'),
    ],
];
