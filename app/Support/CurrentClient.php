<?php

namespace App\Support;

use App\Models\Client;
use Closure;

/**
 * The client whose data the current request or job may touch.
 *
 * HTTP requests: ResolveClient binds it for every authenticated API call. An
 * admin who has not picked a client is bound to "none", so tenant models return
 * no rows instead of every client's rows (fail closed).
 *
 * Console and queue code is not bound by default, so jobs must call runAs()
 * (or pass client_id explicitly).
 */
class CurrentClient
{
    private bool $bound = false;

    private ?int $id = null;

    public function bind(?int $clientId): void
    {
        $this->bound = true;
        $this->id = $clientId;
    }

    public function unbind(): void
    {
        $this->bound = false;
        $this->id = null;
    }

    public function isBound(): bool
    {
        return $this->bound;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * Run a callback scoped to one client, then restore the previous state.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Client|int $client, Closure $callback): mixed
    {
        $previous = [$this->bound, $this->id];
        $this->bind($client instanceof Client ? $client->id : $client);

        try {
            return $callback();
        } finally {
            [$this->bound, $this->id] = $previous;
        }
    }
}
