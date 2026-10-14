<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not tenant-scoped on purpose: it is only used by sync code that already
 * knows which client it is working for.
 */
class QuinosSession extends Model
{
    protected $fillable = ['client_id', 'cookies', 'inertia_version', 'logged_in_at'];

    protected $hidden = ['cookies'];

    protected $casts = [
        // Session cookies are as good as a password: store them encrypted.
        'cookies' => 'encrypted:array',
        'logged_in_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
