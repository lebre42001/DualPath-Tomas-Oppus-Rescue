<?php

// In-memory UI fixture: no real account/report/session is created.
require __DIR__.'/../../web_system/vendor/autoload.php';
$app = require __DIR__.'/../../web_system/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Illuminate\Support\Facades\URL::forceRootUrl('http://incident.fixture');
$user = new App\Models\User(['name' => 'Category Fixture', 'email' => 'fixture@example.test', 'role' => 'civilian', 'is_admin' => false, 'account_verified_at' => now()]);
$user->id = 999999;
$user->setRelation('responderProfile', new App\Models\ResponderProfile(['assigned_station' => 'Civilian Mobile']));
Illuminate\Support\Facades\Auth::setUser($user);
$request = Illuminate\Http\Request::create('http://incident.fixture/reports/create');
$request->setRouteResolver(fn () => Illuminate\Support\Facades\Route::getRoutes()->getByName('reports.create'));
$request->setUserResolver(fn () => $user);
$app->instance('request', $request);
echo view('reports.create', ['submissionId' => (string) Illuminate\Support\Str::uuid(), 'errors' => new Illuminate\Support\ViewErrorBag])->render();
