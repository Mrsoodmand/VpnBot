<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Panels extends Model
{
    public function inbounds()
    {
        return $this->hasMany(Inbounds::class, 'panel_id');
    }

    protected function casts()
    {
        return [
            'detail' => 'array',
        ];
    }
}
