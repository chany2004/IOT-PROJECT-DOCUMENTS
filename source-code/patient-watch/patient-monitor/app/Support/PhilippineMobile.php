<?php

namespace App\Support;

class PhilippineMobile
{
    public static function normalize(?string $number): ?string
    {
        $number = preg_replace('/[\s()\-]/', '', trim($number ?? ''));
        if (preg_match('/^09\d{9}$/D', $number)) {
            return '+63'.substr($number, 1);
        }
        if (preg_match('/^\+?639\d{9}$/D', $number)) {
            return '+'.ltrim($number, '+');
        }

        return null;
    }
}
