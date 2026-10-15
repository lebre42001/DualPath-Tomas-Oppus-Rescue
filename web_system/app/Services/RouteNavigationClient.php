<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class RouteNavigationClient
{
    public function enabled(): bool
    {
        return (bool) config('services.routing.enabled', ! app()->environment('testing'));
    }

    public function route(float $originLatitude, float $originLongitude, float $destinationLatitude, float $destinationLongitude): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Routing service is disabled.');
        }

        return Cache::remember(
            $this->cacheKey($originLatitude, $originLongitude, $destinationLatitude, $destinationLongitude),
            now()->addMinutes($this->cacheTtlMinutes()),
            fn (): array => $this->requestRoute($originLatitude, $originLongitude, $destinationLatitude, $destinationLongitude),
        );
    }

    private function requestRoute(float $originLatitude, float $originLongitude, float $destinationLatitude, float $destinationLongitude): array
    {
        $baseUrl = rtrim((string) config('services.routing.url', 'https://router.project-osrm.org'), '/');
        $profile = (string) config('services.routing.profile', 'driving');

        $response = Http::acceptJson()
            ->timeout($this->timeout())
            ->retry(1, 150)
            ->get(
                sprintf(
                    '%s/route/v1/%s/%s,%s;%s,%s',
                    $baseUrl,
                    rawurlencode($profile),
                    $originLongitude,
                    $originLatitude,
                    $destinationLongitude,
                    $destinationLatitude,
                ),
                [
                    'overview' => 'full',
                    'geometries' => 'geojson',
                    'steps' => 'true',
                ],
            );

        $response->throw();

        $payload = $response->json();
        if (! is_array($payload) || ($payload['code'] ?? null) !== 'Ok') {
            throw new RuntimeException('Routing service returned an invalid route response.');
        }

        $route = $payload['routes'][0] ?? null;
        if (! is_array($route)) {
            throw new RuntimeException('Routing service did not return a usable route.');
        }

        return [
            'provider' => (string) config('services.routing.provider', 'OSRM Public Routing'),
            'profile' => $profile,
            'distance_meters' => (float) ($route['distance'] ?? 0),
            'duration_seconds' => (int) ceil((float) ($route['duration'] ?? 0)),
            'geometry' => $this->normalizeGeometry($route['geometry'] ?? null),
            'steps' => $this->normalizeSteps(collect($route['legs'] ?? [])),
        ];
    }

    private function normalizeGeometry(mixed $geometry): array
    {
        if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString') {
            return [];
        }

        return collect($geometry['coordinates'] ?? [])
            ->filter(fn ($pair): bool => is_array($pair) && count($pair) >= 2)
            ->map(function (array $pair): array {
                return [
                    'lat' => (float) $pair[1],
                    'lng' => (float) $pair[0],
                ];
            })
            ->values()
            ->all();
    }

    private function normalizeSteps(Collection $legs): array
    {
        return $legs
            ->flatMap(fn ($leg): array => is_array($leg) ? ($leg['steps'] ?? []) : [])
            ->filter(fn ($step): bool => is_array($step))
            ->map(function (array $step): array {
                return [
                    'instruction' => $this->instructionForStep($step),
                    'distance_meters' => (float) ($step['distance'] ?? 0),
                    'duration_seconds' => (int) ceil((float) ($step['duration'] ?? 0)),
                    'road_name' => trim((string) ($step['name'] ?? '')),
                    'mode' => (string) ($step['mode'] ?? 'driving'),
                ];
            })
            ->filter(fn (array $step): bool => filled($step['instruction']))
            ->values()
            ->all();
    }

    private function instructionForStep(array $step): string
    {
        $maneuver = is_array($step['maneuver'] ?? null) ? $step['maneuver'] : [];
        $type = Str::of((string) ($maneuver['type'] ?? 'continue'))->replace('-', ' ')->title()->value();
        $modifier = Str::of((string) ($maneuver['modifier'] ?? ''))->replace('-', ' ')->title()->value();
        $roadName = trim((string) ($step['name'] ?? ''));

        return match ($type) {
            'Depart' => $roadName !== '' ? 'Depart the command center and follow '.$roadName : 'Depart the command center.',
            'Arrive' => 'Arrive at the sender mobile location.',
            'Roundabout' => $roadName !== '' ? 'Enter the roundabout toward '.$roadName.'.' : 'Enter the roundabout.',
            default => trim($type.' '.($modifier !== '' ? $modifier.' ' : '').($roadName !== '' ? 'onto '.$roadName : '')),
        };
    }

    private function timeout(): int
    {
        return max(2, (int) config('services.routing.timeout', 8));
    }

    private function cacheTtlMinutes(): int
    {
        return max(1, (int) config('services.routing.cache_ttl_minutes', 10));
    }

    private function cacheKey(float $originLatitude, float $originLongitude, float $destinationLatitude, float $destinationLongitude): string
    {
        return sprintf(
            'stitch-routing:%s:%s:%s:%s:%s',
            config('services.routing.profile', 'driving'),
            number_format($originLatitude, 6, '.', ''),
            number_format($originLongitude, 6, '.', ''),
            number_format($destinationLatitude, 6, '.', ''),
            number_format($destinationLongitude, 6, '.', ''),
        );
    }
}
