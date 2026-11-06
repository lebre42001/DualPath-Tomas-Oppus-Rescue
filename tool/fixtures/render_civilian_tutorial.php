<?php

// In-memory civilian fixture only. No users, reports or credentials are saved.
require __DIR__.'/../../web_system/vendor/autoload.php';
$app = require __DIR__.'/../../web_system/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Illuminate\Support\Facades\URL::forceRootUrl('http://tutorial.fixture');
$user = new App\Models\User(['name' => 'Tutorial Civilian', 'email' => 'civilian@example.test', 'role' => 'civilian', 'is_admin' => false, 'account_verified_at' => ($argv[2] ?? '') === '1' ? now() : null]);
$user->id = (int) ($argv[1] ?? 1);
$user->setRelation('responderProfile', new App\Models\ResponderProfile(['assigned_station' => 'Civilian Mobile']));
Illuminate\Support\Facades\Auth::setUser($user);
$request = Illuminate\Http\Request::create('http://tutorial.fixture/dashboard');
$route = Illuminate\Support\Facades\Route::getRoutes()->getByName('dashboard');
$request->setRouteResolver(fn () => $route);
$request->setUserResolver(fn () => $user);
$app->instance('request', $request);
echo view('dashboard', [
    'dashboardMode' => 'civilian', 'user' => $user,
    'stats' => ['total' => 0, 'active' => 0, 'resolved' => 0, 'today' => 0],
    'reports' => collect(), 'fatalAlerts' => collect(),
    'errors' => new Illuminate\Support\ViewErrorBag,
])->render();
