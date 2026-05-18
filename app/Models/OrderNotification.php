<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNotification extends Model
{
    protected $table = 'order_notifications';

    protected $fillable = [
        'order_id',
        'user_id',
        'type',
        'channel',
        'status',
        'idempotency_key',
        'payload',
        'queued_at',
        'sent_at',
        'attempts',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at'   => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
