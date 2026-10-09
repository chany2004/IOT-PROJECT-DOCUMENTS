<?php

namespace App\Support;

final class CameraJpeg
{
    public static function isUsable(string $jpeg): bool
    {
        if (!function_exists('imagecreatefromstring') || strlen($jpeg) < 4
            || !str_starts_with($jpeg, "\xFF\xD8") || !str_ends_with($jpeg, "\xFF\xD9")) {
            return false;
        }

        $dimensions = @getimagesizefromstring($jpeg);
        if (!$dimensions || $dimensions[2] !== IMAGETYPE_JPEG) return false;

        $image = @imagecreatefromstring($jpeg);
        if ($image === false) return false;

        try {
            return !self::hasDecoderFillTail($image);
        } finally {
            imagedestroy($image);
        }
    }

    private static function hasDecoderFillTail(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 64 || $height < 64) return false;

        // GD can return an image even when the camera's JPEG entropy ends
        // early. On this camera it fills the missing bottom scanlines with
        // exactly RGB(128, 128, 128), while the upper image still has detail.
        $columns = [];
        for ($i = 0; $i < 32; $i++) {
            $columns[] = (int) round(($width - 1) * $i / 31);
        }
        $tailRows = max(12, (int) ceil($height * 0.05));
        for ($y = $height - 1; $y >= $height - $tailRows; $y--) {
            foreach ($columns as $x) {
                if ((imagecolorat($image, $x, $y) & 0xFFFFFF) !== 0x808080) return false;
            }
        }
        $upperY = (int) ($height * 0.3);
        foreach ($columns as $x) {
            if ((imagecolorat($image, $x, $upperY) & 0xFFFFFF) !== 0x808080) return true;
        }

        return false;
    }
}
