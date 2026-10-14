<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A tiny pretend quinosbackend.com for tests: CSRF cookie, login form, sessions,
 * Inertia version checks, one cursor-paginated list and one report endpoint.
 *
 * Every secret in here is made up (FAKE-PIN, FAKE-TOKEN). No real payload data.
 */
class FakeQuinos
{
    public const BASE = 'https://quinosbackend.com';

    public string $email = 'zali@example.com';

    public string $password = 'secret-quinos-password';

    public string $version = 'v1';

    /** Pages of /items, keyed by the cursor that requests them ('' = first page). */
    public array $itemPages = [];

    /** Generic lists: path => rows (newest first). Served 2 per page with cursors "p2", "p4", ... */
    public array $lists = [];

    /** Detail pages: path => page props. */
    public array $details = [];

    public int $perPage = 2;

    public ?string $validSession = null;

    public int $loginCount = 0;

    public int $csrfFailuresLeft = 0;

    public int $serverErrorsLeft = 0;

    public bool $rateLimited = false;

    /** Login "works" but the session never becomes valid (captcha / 2FA). */
    public bool $sessionNeverValid = false;

    /** @var array<int, array{method: string, path: string, query: array, headers: array, body: mixed}> */
    public array $log = [];

    private string $xsrf = 'xsrf-token-1==';

    public static function install(): self
    {
        $fake = new self;
        $fake->itemPages = [
            '' => ['rows' => [['code' => 'A']], 'next' => 'c2'],
            'c2' => ['rows' => [['code' => 'B']], 'next' => 'c3'],
            'c3' => ['rows' => [['code' => 'C']], 'next' => null],
        ];

        Http::fake(fn (Request $request) => $fake->handle($request));

        config([
            'quinos.base_url' => self::BASE,
            'quinos.delay_ms' => 0,
            'quinos.retry_delay_ms' => 0,
        ]);

        return $fake;
    }

    /** Make the server forget our session (as if it expired). */
    public function expireSession(): void
    {
        $this->validSession = null;
    }

    public function requests(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->log,
            fn ($r) => $r['method'] === $method && $r['path'] === $path,
        ));
    }

    public function handle(Request $request): PromiseInterface
    {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '/';
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $this->log[] = [
            'method' => $request->method(),
            'path' => $path,
            'query' => $query,
            'headers' => $request->headers(),
            'body' => $request->method() === 'POST' ? $request->data() : null,
        ];

        if ($this->serverErrorsLeft > 0 && $path !== '/login') {
            $this->serverErrorsLeft--;

            return Http::response('boom', 500);
        }

        $isInertia = $request->hasHeader('X-Inertia');

        return match (true) {
            $path === '/login' && $request->method() === 'GET' => $this->loginPage(),
            $path === '/login' && $request->method() === 'POST' => $this->submitLogin($request),
            ! $this->hasValidSession($request) => $this->redirectToLogin(),
            $isInertia && $this->versionMismatch($request) => $this->versionConflict($path),
            $path === '/dashboard' => $this->html($this->pageArray('Dashboard', [])),
            $path === '/salesTypes' => $this->respond($isInertia, $this->pageArray('SalesType/SalesTypeIndex', [
                'rows' => ['data' => [['id' => 672, 'name' => 'DINE IN', 'active' => true], ['id' => 677, 'name' => 'GRAB', 'active' => true]], 'meta' => ['next_cursor' => null]],
            ])),
            $path === '/items' && ! isset($this->lists['/items']) => $this->items($query, $isInertia),
            isset($this->lists[$path]) => $this->listPage($path, $query, $isInertia),
            isset($this->details[$path]) => $this->respond($isInertia, $this->pageArray('Detail', $this->details[$path])),
            $path === '/reports/pos/transactionDetailReport' => $this->respond($isInertia, $this->pageArray('Report/Pos/TransactionDetailReport', [
                'parameter' => $request->data(),
                'result' => [['invoice_no' => 'INV-000001']],
            ])),
            default => Http::response('not found', 404),
        };
    }

    // ------------------------------------------------------------- responses

    private function loginPage(): PromiseInterface
    {
        return Http::response(
            $this->htmlBody($this->pageArray('Auth/Login', [], withAuth: false)),
            200,
            ['Content-Type' => 'text/html', 'Set-Cookie' => [
                'XSRF-TOKEN='.urlencode($this->xsrf).'; path=/; secure; samesite=lax',
                'quinos_session=guest-'.count($this->log).'; path=/; httponly',
            ]],
        );
    }

    private function submitLogin(Request $request): PromiseInterface
    {
        if ($this->rateLimited) {
            return Http::response(['message' => 'Too Many Attempts.'], 429);
        }

        $sent = $request->header('X-XSRF-TOKEN')[0] ?? null;

        if ($this->csrfFailuresLeft > 0) {
            $this->csrfFailuresLeft--;
            $this->xsrf = 'xsrf-token-'.(count($this->log)).'==';

            return Http::response(['message' => 'CSRF token mismatch.'], 419);
        }

        if ($sent !== $this->xsrf) {
            return Http::response(['message' => 'CSRF token mismatch.'], 419);
        }

        $data = $request->data();

        if (($data['email'] ?? null) !== $this->email || ($data['password'] ?? null) !== $this->password) {
            return Http::response([
                'message' => 'These credentials do not match our records.',
                'errors' => ['email' => ['These credentials do not match our records.']],
            ], 422);
        }

        $this->loginCount++;
        $session = 'sess-'.$this->loginCount;
        $this->validSession = $this->sessionNeverValid ? null : $session;

        return Http::response('', 302, [
            'Location' => self::BASE.'/dashboard',
            'Set-Cookie' => ['quinos_session='.$session.'; path=/; httponly'],
        ]);
    }

    private function hasValidSession(Request $request): bool
    {
        if ($this->validSession === null) {
            return false;
        }

        $cookies = implode(';', $request->header('Cookie'));

        return str_contains($cookies, 'quinos_session='.$this->validSession.'');
    }

    private function versionMismatch(Request $request): bool
    {
        return ($request->header('X-Inertia-Version')[0] ?? null) !== $this->version;
    }

    private function redirectToLogin(): PromiseInterface
    {
        return Http::response('', 302, ['Location' => self::BASE.'/login']);
    }

    private function versionConflict(string $path): PromiseInterface
    {
        return Http::response('', 409, ['X-Inertia-Location' => self::BASE.$path]);
    }

    private function listPage(string $path, array $query, bool $isInertia): PromiseInterface
    {
        $offset = (int) ltrim($query['cursor'] ?? '', 'p');
        $rows = array_slice($this->lists[$path], $offset, $this->perPage);
        $next = $offset + $this->perPage < count($this->lists[$path]) ? 'p'.($offset + $this->perPage) : null;

        return $this->respond($isInertia, $this->pageArray('List', [
            'rows' => ['data' => $rows, 'meta' => ['next_cursor' => $next]],
        ]));
    }

    private function items(array $query, bool $isInertia): PromiseInterface
    {
        $page = $this->itemPages[$query['cursor'] ?? ''] ?? ['rows' => [], 'next' => null];

        return $this->respond($isInertia, $this->pageArray('Item/ItemIndex', [
            'rows' => ['data' => $page['rows'], 'meta' => ['next_cursor' => $page['next']]],
            'search' => $query['search'] ?? null,
        ]));
    }

    /** Like the real app: JSON for Inertia requests, the HTML shell otherwise. */
    private function respond(bool $isInertia, array $page): PromiseInterface
    {
        return $isInertia ? $this->inertia($page) : $this->html($page);
    }

    private function inertia(array $page): PromiseInterface
    {
        return Http::response($page, 200, ['X-Inertia' => 'true', 'Vary' => 'X-Inertia']);
    }

    private function html(array $page): PromiseInterface
    {
        return Http::response($this->htmlBody($page), 200, ['Content-Type' => 'text/html']);
    }

    private function htmlBody(array $page): string
    {
        return '<!DOCTYPE html><html><body><div id="app" data-page="'
            .htmlspecialchars(json_encode($page), ENT_QUOTES).'"></div></body></html>';
    }

    private function pageArray(string $component, array $props, bool $withAuth = true): array
    {
        if ($withAuth) {
            $props['auth'] = ['user' => [
                'id' => 1,
                'email' => $this->email,
                'pin' => 'FAKE-PIN',
                'company' => ['id' => 224, 'name' => 'Zali Uluwatu', 'token' => 'FAKE-TOKEN'],
            ]];
            $props['ziggy'] = ['routes' => ['salesTypes.index' => ['uri' => 'salesTypes']]];
        }

        return ['component' => $component, 'props' => $props, 'url' => '/x', 'version' => $this->version];
    }
}
