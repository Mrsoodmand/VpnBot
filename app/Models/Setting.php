<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => app(\App\Services\OrderCheckSchedule::class)->forgetSettings());
        static::deleted(fn () => app(\App\Services\OrderCheckSchedule::class)->forgetSettings());
    }

    protected $fillable = ['key','name','value'];
}
