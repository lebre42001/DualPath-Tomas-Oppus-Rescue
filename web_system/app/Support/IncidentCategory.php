<?php

namespace App\Support;

/** Shared reporting labels and AI category vocabulary. */
final class IncidentCategory
{
    public const LABELS = [
        'road_accident' => 'Road Accident',
        'fire_incident' => 'Fire Incident',
        'flood_landslide' => 'Flood / Landslide',
        'medical_emergency' => 'Medical Emergency',
        'security_incident' => 'Security Incident',
        'other' => 'Other',
    ];

    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return 'road_accident'; // Legacy clients did not send a category.
        }

        $key = trim(preg_replace('/[\s_\/\-]+/u', '_', mb_strtolower(trim($value))), '_');

        return match ($key) {
            'road_accident', 'road', 'vehicle_accident', 'vehicular_accident', 'road_incident',
            'vehicular_collision', 'vehicle_collision', 'motorcycle_collision', 'motorcycle_accident', 'road_crash', 'road_emergency' => 'road_accident',
            'fire_incident', 'fire' => 'fire_incident',
            'flood_landslide', 'flood', 'landslide' => 'flood_landslide',
            'medical_emergency', 'medical' => 'medical_emergency',
            'security_incident', 'security' => 'security_incident',
            'other' => 'other',
            default => null,
        };
    }

    public static function label(?string $value): ?string
    {
        $key = self::normalize($value);

        return $key === null ? null : self::LABELS[$key];
    }
}
