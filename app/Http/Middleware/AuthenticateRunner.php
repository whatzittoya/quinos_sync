<?php
namespace App\Http\Middleware;
use App\Models\RunnerToken;
use Closure;
use Illuminate\Http\Request;
class AuthenticateRunner
{
    public function handle(Request $request, Closure $next)
    {
        $secret = $request->bearerToken();
        abort_unless(is_string($secret) && strlen($secret) >= 32, 401, 'Invalid runner token.');
        $token = RunnerToken::where('token_hash', hash('sha256', $secret))->whereNull('revoked_at')->first();
        abort_unless($token, 401, 'Invalid runner token.');
        $token->update(['last_used_at' => now()]);
        $request->attributes->set('runner_token', $token);
        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
