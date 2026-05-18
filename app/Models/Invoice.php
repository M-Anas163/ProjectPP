<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'invoice_number',
        'amount',
        'status',
        'idempotency_key',
        'queued_at',
        'generated_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'queued_at'    => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
