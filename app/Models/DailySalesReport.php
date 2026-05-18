<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySalesReport extends Model
{
    protected $fillable = [
        'report_date', 'total_orders', 'completed_orders',
        'failed_orders', 'total_revenue', 'avg_order_value',
        'total_chunks', 'chunk_size', 'processed_chunks',
        'last_processed_chunk', 'status', 'processing_time_seconds',
        'records_per_second', 'batch_id',
    ];

    protected $casts = [
        'report_date'   => 'date',
        'total_revenue' => 'decimal:2',
    ];

    public function chunkLogs()
    {
        return $this->hasMany(BatchChunkLog::class, 'batch_id', 'batch_id');
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_chunks === 0) return 0;
        return round(($this->processed_chunks / $this->total_chunks) * 100, 1);
    }
}
