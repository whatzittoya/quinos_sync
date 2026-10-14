<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'quinos_email', 'quinos_password', 'quinos_company_id',
        'timezone', 'shift_assign_by', 'active',
    ];

    protected $hidden = ['quinos_password'];

    protected $casts = [
        // Encrypted with APP_KEY; never returned by the API.
        'quinos_password' => 'encrypted',
        'active' => 'boolean',
        'scheduled' => 'boolean',
        'last_login_at' => 'datetime',
        'master_synced_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(ClientShift::class)->orderBy('position');
    }

    public function hasQuinosCredentials(): bool
    {
        return filled($this->quinos_email) && filled($this->quinos_password);
    }
}
