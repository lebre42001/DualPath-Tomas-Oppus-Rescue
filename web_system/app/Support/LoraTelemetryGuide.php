<?php

namespace App\Support;

/** Display guides for this deployment; not calibrated delivery/SLA thresholds. */
final class LoraTelemetryGuide
{
    public const RTT_FAST_MAX = 1000;
    public const RTT_MODERATE_MAX = 3000;
    public const RSSI_STRONG_MIN = -90;
    public const RSSI_FAIR_MIN = -110;

    public static function rtt(?int $milliseconds): ?array
    {
        if ($milliseconds === null || $milliseconds < 0) {
            return null;
        }

        return match (true) {
            $milliseconds <= self::RTT_FAST_MAX => ['label' => 'Fast', 'tone' => 'green'],
            $milliseconds <= self::RTT_MODERATE_MAX => ['label' => 'Moderate', 'tone' => 'amber'],
            default => ['label' => 'Slow', 'tone' => 'red'],
        };
    }

    public static function rssi(?int $dbm): ?array
    {
        if ($dbm === null || $dbm >= 0) {
            return null;
        }

        return match (true) {
            $dbm >= self::RSSI_STRONG_MIN => ['label' => 'Strong', 'tone' => 'green'],
            $dbm >= self::RSSI_FAIR_MIN => ['label' => 'Fair', 'tone' => 'amber'],
            default => ['label' => 'Weak', 'tone' => 'red'],
        };
    }
}
