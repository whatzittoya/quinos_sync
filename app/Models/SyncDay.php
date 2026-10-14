<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

/** Sync state of one data package on one day (the calendar reads this). */
class SyncDay extends Model
{
    use BelongsToClient;

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'synced_at' => 'datetime',
        'partial' => 'boolean',
    ];
}
