<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant isolation for models that carry a client_id.
 *
 * When CurrentClient is bound, queries only see that client's rows. A bound
 * "none" client sees nothing. When nothing is bound (console, queue) the scope
 * is skipped, so background code must always filter by client_id itself.
 */
trait BelongsToClient
{
    public static function bootBelongsToClient(): void
    {
        static::addGlobalScope('client', function (Builder $query) {
            $current = app(CurrentClient::class);

            if (! $current->isBound()) {
                return;
            }

            if ($current->id() === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($query->getModel()->getTable().'.client_id', $current->id());
        });

        static::creating(function ($model) {
            if ($model->client_id === null) {
                $current = app(CurrentClient::class);

                if ($current->isBound() && $current->id() !== null) {
                    $model->client_id = $current->id();
                }
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
