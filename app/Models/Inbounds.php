<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Inbounds extends Model
{
    public function scopeCatalogPresent(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('setting->_bot_catalog_missing')
            ->orWhere('setting->_bot_catalog_missing', false));
    }

    public function scopeCatalogMissing(Builder $query): Builder
    {
        return $query->where('setting->_bot_catalog_missing', true);
    }

    public function isCatalogMissing(): bool
    {
        return ! empty((json_decode((string) $this->setting, true) ?: [])['_bot_catalog_missing']);
    }

    public function panel()
    {
        return $this->belongsTo(Panels::class);
    }
}
