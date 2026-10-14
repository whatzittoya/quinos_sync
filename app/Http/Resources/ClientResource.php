<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quinos_email' => $this->quinos_email,
            // The password itself is never sent back.
            'has_quinos_password' => filled($this->quinos_password),
            'quinos_company_id' => $this->quinos_company_id,
            'timezone' => $this->timezone,
            'shift_assign_by' => $this->shift_assign_by,
            'active' => $this->active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
