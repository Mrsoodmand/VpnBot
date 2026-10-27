<?php

namespace App\Services;

use App\Models\Setting;

class OrderNotificationSettings
{
    public static function load(): array
    {
        $values = Setting::whereIn('key', ['renew', 'extra', 'notify_renew', 'notify_volume', 'notify_hours', 'notify_gb', 'notify_interval'])->pluck('value', 'key');

        return [
            'renew' => (int) ($values['renew'] ?? 1) === 1 && (int) ($values['notify_renew'] ?? 1) === 1,
            'volume' => (int) ($values['extra'] ?? 1) === 1 && (int) ($values['notify_volume'] ?? 1) === 1,
            'hours' => self::number($values['notify_hours'] ?? null, 24, 1, 720),
            'gb' => self::number($values['notify_gb'] ?? null, 1, 0.1, 1000),
            'interval' => (int) self::number($values['notify_interval'] ?? null, 10, 5, 60),
        ];
    }

    private static function number($value, $default, $min, $max): float
    {
        return is_numeric($value) && $value >= $min && $value <= $max ? (float) $value : $default;
    }
}
