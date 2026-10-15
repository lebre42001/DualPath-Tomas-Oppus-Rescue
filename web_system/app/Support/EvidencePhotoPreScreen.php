<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class EvidencePhotoPreScreen
{
    public const REJECTION_MESSAGE = 'Upload a real accident or emergency scene photo. Screenshots, app UI, selfie-only, food, pet, room, or unrelated dummy photos are not accepted as evidence.';

    /**
     * Local file checks for obvious screenshot, app UI, or profile-style uploads.
     *
     * These filename, format, and dimension heuristics do not establish scene
     * relevance or severity. No remote image model is called; responders review
     * the retained photo as supporting evidence for the submitted description.
     *
     * @return array{accepted: bool, rejection_message: string|null, reasons: list<string>}
     */
    public static function inspect(UploadedFile $file): array
    {
        $filename = Str::lower($file->getClientOriginalName());
        $extension = Str::lower($file->getClientOriginalExtension());
        $mimeType = Str::lower((string) $file->getMimeType());
        $reasons = [];

        $filenameTokens = [
            'avatar',
            'cat',
            'dashboard',
            'dog',
            'facebook',
            'food',
            'icon',
            'instagram',
            'login',
            'logo',
            'messenger',
            'mockup',
            'pet',
            'profile',
            'register',
            'room',
            'screen',
            'screenshot',
            'selfie',
            'settings',
            'tiktok',
            'ui',
        ];
        $hasSuspiciousFilename = collect($filenameTokens)
            ->contains(fn (string $token): bool => Str::contains($filename, $token));

        if ($hasSuspiciousFilename) {
            $reasons[] = 'The file name looks like a screenshot, app UI, profile image, selfie, pet, food, room, or other dummy content.';
        }

        $isScreenshotMime = in_array($extension, ['png', 'webp'], true)
            || in_array($mimeType, ['image/png', 'image/webp'], true);

        if ($isScreenshotMime) {
            $reasons[] = 'The image format is commonly used by screenshots or app UI exports.';
        }

        $imageInfo = self::imageInfo($file);
        $aspectRatio = null;
        $shortSide = null;

        if ($imageInfo !== null) {
            [$width, $height] = $imageInfo;
            $shortSide = min($width, $height);
            $longSide = max($width, $height);
            $aspectRatio = $shortSide > 0 ? $longSide / $shortSide : null;

            if ($shortSide > 0 && $shortSide < 320) {
                $reasons[] = "The image is very small ({$width}x{$height}), which often means it is an icon, UI asset, or low-detail dummy file.";
            }

            if ($aspectRatio !== null && $aspectRatio >= 2.05) {
                $reasons[] = "The image shape is screenshot-like ({$width}x{$height}) instead of a normal scene photo.";
            }
        }

        $reject = false;

        if ($hasSuspiciousFilename && ($isScreenshotMime || ($aspectRatio !== null && $aspectRatio >= 1.85) || ($shortSide !== null && $shortSide < 360))) {
            $reject = true;
        }

        if ($isScreenshotMime && $aspectRatio !== null && $aspectRatio >= 2.25) {
            $reject = true;
        }

        if (count($reasons) >= 3 && ($hasSuspiciousFilename || $isScreenshotMime)) {
            $reject = true;
        }

        return [
            'accepted' => ! $reject,
            'rejection_message' => $reject ? self::REJECTION_MESSAGE : null,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array{0:int,1:int}|null
     */
    private static function imageInfo(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            return null;
        }

        $info = @getimagesize($path);

        if (! is_array($info) || ! isset($info[0], $info[1])) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }
}
