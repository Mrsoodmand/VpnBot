<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (Orders $order) => app(\App\Services\OrderCheckSchedule::class)->refresh($order));
        static::deleted(fn (Orders $order) => app(\App\Services\OrderCheckSchedule::class)->remove($order->id));
    }

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DATA_EXHAUSTED = 'data_exhausted';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** Keep website-only dedicated services out of bot queries, including old imports. */
    public function scopeBotManaged($query)
    {
        foreach (['orders.detail->service_type', 'orders.detail->raw->service_type'] as $field) {
            $query->where(function ($q) use ($field) {
                $q->whereNull($field)->orWhere($field, '!=', 'dedicated');
            });
        }
        return $query;
    }

    public function isSiteManagedDedicated(): bool
    {
        $detail = is_array($this->detail) ? $this->detail : [];
        return ($detail['service_type'] ?? '') === 'dedicated'
            || ($detail['raw']['service_type'] ?? '') === 'dedicated';
    }

    protected function casts()
    {
        return [
            'detail' => 'array',
            'expire_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected $fillable = [
        'id',
        'user_id',
        'remark',
        'uid',
        'sub_id',
        'plan',
        'status',
        'reminded',
        'panel_id',
        'inbound_id',
        'detail',
        'system_type',
        'expire_at',
    ];
}
