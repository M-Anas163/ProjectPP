<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'total_amount',
        'processing_mode',
        'queued_at',
        'processed_at'
    ];

    protected $casts = [
        'total_amount'  => 'decimal:2',
        'queued_at'     => 'datetime',
        'processed_at'  => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getProcessingDurationAttribute()
    {
        if ($this->queued_at && $this->processed_at) {
            return $this->processed_at->diffInMilliseconds($this->queued_at);
        }
        return null;
    }
}
