<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class LoraReporterIdentity
{
    /**
     * The envelope stays inside the existing signed SOS description field so
     * older gateways and relays can forward it unchanged. Lengths are bytes.
     *
     * @return array{name: string, description: string}|null
     */
    public static function decode(string $message): ?array
    {
        if (! str_starts_with($message, '@N1:')) {
            return null;
        }

        if (strlen($message) > 70 || ! preg_match('/\A@N1:([1-9]|1[0-9]|2[0-4]):/', $message, $match)) {
            self::invalid();
        }

        $offset = strlen($match[0]);
        $name = substr($message, $offset, (int) $match[1]);
        $description = substr($message, $offset + (int) $match[1]);
        if (strlen($name) !== (int) $match[1] || ! mb_check_encoding($name, 'UTF-8') ||
            ! mb_check_encoding($description, 'UTF-8') || trim($name) === '' || trim($description) === '' ||
            trim($name) !== $name || trim($description) !== $description ||
            preg_match('/[|\x00-\x1f\x7f]/', $name.$description)) {
            self::invalid();
        }

        return ['name' => $name, 'description' => $description];
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['description' => 'Invalid LoRa reporter name or description.']);
    }
}
