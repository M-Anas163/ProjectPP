<?php
// app/Models/BatchChunkLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BatchChunkLog extends Model
{
    protected $fillable = [
        'batch_id', 'chunk_number', 'offset', 'limit',
        'records_processed', 'chunk_time_seconds', 'status',
    ];
}
