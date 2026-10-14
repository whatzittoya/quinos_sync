<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Support\CurrentClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which client's data this request can see.
 *
 * Client users are pinned to their own client and cannot override it.
 * Admins choose one with the X-Client-Id header; without it they are bound to
 * "none" and tenant data stays empty.
 */
class ResolveClient
{
    public function __construct(private CurrentClient $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            $this->current->bind(null);
        } elseif ($user->isAdmin()) {
            $requested = $request->header('X-Client-Id');
            $clientId = null;

            if (filled($requested) && ctype_digit((string) $requested)
                && Client::whereKey((int) $requested)->exists()) {
                $clientId = (int) $requested;
            }

            $this->current->bind($clientId);
        } else {
            $this->current->bind($user->client_id);
        }

        try {
            return $next($request);
        } finally {
            $this->current->unbind();
        }
    }
}
