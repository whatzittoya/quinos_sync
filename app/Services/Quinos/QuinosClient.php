<?php

namespace App\Services\Quinos;

use App\Exceptions\Quinos\QuinosAuthException;
use App\Exceptions\Quinos\QuinosRequestException;
use App\Models\Client;
use App\Models\QuinosSession;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Reads data from quinosbackend.com for one client, the way a browser would.
 *
 * quinosbackend.com is a Laravel + Inertia app without a public API, so we log
 * in through its normal login form, keep the session cookies (encrypted, in the
 * quinos_sessions table) and ask for Inertia page data as JSON. When the
 * session expires we log in again once and retry.
 *
 * Nothing here may log or throw credentials, cookies or tokens.
 */
class QuinosClient
{
    /** @var array<string, string> cookie name => raw value, as sent by the server */
    private array $cookies = [];

    private ?string $version = null;

    private bool $sessionLoaded = false;

    private float $lastRequestAt = 0.0;

    public function __construct(private readonly Client $client) {}

    public static function for(Client $client): self
    {
        return new self($client);
    }

    // ---------------------------------------------------------------- public

    /** GET an Inertia page (list, detail, ...). */
    public function get(string $path, array $query = []): QuinosPage
    {
        return $this->inertia('GET', $path, $query);
    }

    /** POST to an Inertia endpoint (reports take their filters this way). */
    public function post(string $path, array $data = []): QuinosPage
    {
        return $this->inertia('POST', $path, [], $data);
    }

    /**
     * Walk a cursor-paginated list page by page.
     *
     * @return Generator<int, QuinosPage>
     */
    public function pages(string $path, array $query = []): Generator
    {
        $cursor = null;
        $seen = [];

        for ($i = 0; $i < config('quinos.max_pages'); $i++) {
            $page = $this->get($path, $cursor === null ? $query : ['cursor' => $cursor] + $query);

            yield $page;

            $cursor = $page->nextCursor();

            if ($cursor === null) {
                return;
            }

            if (isset($seen[$cursor])) {
                throw new QuinosRequestException("Quinos pagination of {$path} went in a circle.");
            }

            $seen[$cursor] = true;
        }

        throw new QuinosRequestException('Stopped after '.config('quinos.max_pages')." pages of {$path}.");
    }

    /**
     * Every row of a paginated list.
     *
     * @return Generator<int, array>
     */
    public function rows(string $path, array $query = []): Generator
    {
        foreach ($this->pages($path, $query) as $page) {
            // Not `yield from`: every page's rows start at key 0, which would
            // overwrite each other in iterator_to_array() or collect().
            foreach ($page->rows() as $row) {
                yield $row;
            }
        }
    }

    /**
     * Log in from scratch and prove the session works. Returns a small page
     * (sales types) that also tells us which company we are signed in to.
     */
    public function login(): QuinosPage
    {
        if (! $this->client->hasQuinosCredentials()) {
            throw new QuinosAuthException('This client has no Quinos email and password saved.');
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->cookies = [];
            $this->version = null;
            $this->sessionLoaded = true;

            $this->fetchLoginPage();

            $response = $this->request('POST', '/login', [], [
                'email' => $this->client->quinos_email,
                'password' => $this->client->quinos_password,
                'remember' => false,
            ], 'json');

            $status = $response->status();

            if ($status === 419 && $attempt === 1) {
                continue; // stale CSRF token: start over once
            }

            if ($status === 422) {
                $reason = $response->json('errors.email.0') ?? $response->json('message') ?? 'invalid credentials';

                throw new QuinosAuthException('Quinos rejected the login: '.Str::limit((string) $reason, 160, '…'));
            }

            if ($status === 429) {
                throw new QuinosAuthException('Quinos is rate limiting logins. Wait a few minutes and try again.');
            }

            if ($status >= 400 || $this->isLoginRedirect($response)) {
                throw new QuinosAuthException("Quinos login failed (HTTP {$status}).");
            }

            break;
        }

        $page = $this->verifySession();

        $this->client->forceFill(['last_login_at' => now()])->save();
        $this->persist(loggedIn: true);

        return $page;
    }

    /** Cheap authenticated request, used to check that credentials still work. */
    public function ping(): QuinosPage
    {
        return $this->get('/salesTypes');
    }

    // ------------------------------------------------------------- internals

    private function inertia(string $method, string $path, array $query = [], ?array $json = null): QuinosPage
    {
        $this->loadSession();

        if ($this->cookies === []) {
            $this->login();
        }

        $reloggedIn = false;
        $versionRefreshed = false;

        while (true) {
            $response = $this->request($method, $path, $query, $json, 'inertia');

            if ($this->isSessionExpired($response)) {
                $this->relogin($reloggedIn);
                $reloggedIn = true;

                continue;
            }

            if ($response->status() === 409) {
                if ($versionRefreshed) {
                    throw new QuinosRequestException("Quinos keeps rejecting the page version for {$path}.", 409);
                }

                $this->refreshVersion($response);
                $versionRefreshed = true;

                continue;
            }

            if (! $response->successful()) {
                throw new QuinosRequestException("Quinos answered HTTP {$response->status()} for {$method} {$path}.", $response->status());
            }

            $page = $this->decodePage($response, $path);

            if ($page->isLoginPage()) {
                $this->relogin($reloggedIn);
                $reloggedIn = true;

                continue;
            }

            $this->version = $page->version ?? $this->version;
            $this->persist();

            return $page;
        }
    }

    private function relogin(bool $alreadyTried): void
    {
        if ($alreadyTried) {
            throw new QuinosAuthException('Quinos asked us to log in again right after we logged in.');
        }

        $this->login();
    }

    /** Fetch /login as HTML: gives us the CSRF + session cookies and the page version. */
    private function fetchLoginPage(): void
    {
        $response = $this->request('GET', '/login', [], null, 'html');

        if (! $response->successful()) {
            throw new QuinosRequestException("Could not open the Quinos login page (HTTP {$response->status()}).", $response->status());
        }

        if (! isset($this->cookies['XSRF-TOKEN'])) {
            throw new QuinosAuthException('Quinos did not send a CSRF cookie with the login page.');
        }

        $this->version = $this->versionFromHtml($response->body());
    }

    /** After the login form: make sure we really are signed in. */
    private function verifySession(): QuinosPage
    {
        for ($i = 0; $i < 2; $i++) {
            $response = $this->request('GET', '/salesTypes', [], null, 'inertia');

            if ($response->status() === 409 && $i === 0) {
                $this->refreshVersion($response);

                continue;
            }

            break;
        }

        $notSignedIn = new QuinosAuthException(
            'Quinos accepted the login but the session is not signed in. '
            .'A captcha, a two-factor step or a changed login form may be blocking automated login.'
        );

        if ($this->isSessionExpired($response) || ! $response->successful()) {
            throw $notSignedIn;
        }

        $page = $this->decodePage($response, '/salesTypes');

        if ($page->isLoginPage()) {
            throw $notSignedIn;
        }

        $this->version = $page->version ?? $this->version;

        return $page;
    }

    /**
     * Inertia answers 409 when our page version is stale (or missing). The
     * fresh version is only on the normal HTML page, so read it from there.
     */
    private function refreshVersion(Response $conflict): void
    {
        $path = $this->localPath($conflict->header('X-Inertia-Location')) ?? '/';

        for ($hops = 0; $hops < 3; $hops++) {
            $response = $this->request('GET', $path, [], null, 'html');

            if ($this->isLoginRedirect($response)) {
                $this->login();

                return;
            }

            $next = $this->isRedirect($response) ? $this->localPath($response->header('Location')) : null;

            if ($next === null) {
                break;
            }

            $path = $next;
        }

        $version = $this->versionFromHtml($response->body()) ?? $response->json('version');

        if (blank($version)) {
            throw new QuinosRequestException('Could not read the current Quinos page version.');
        }

        $this->version = (string) $version;
    }

    /**
     * One HTTP round trip with our cookies. Never follows redirects, so we can
     * tell "sent to the login page" apart from real answers.
     *
     * @param  'html'|'json'|'inertia'  $mode
     */
    private function request(string $method, string $path, array $query, ?array $json, string $mode): Response
    {
        $attempts = max(1, (int) config('quinos.max_attempts'));
        $base = config('quinos.base_url');

        for ($try = 1; ; $try++) {
            $this->throttle();

            try {
                $response = Http::baseUrl($base)
                    ->timeout((int) config('quinos.timeout'))
                    ->withoutRedirecting()
                    ->withHeaders($this->headers($mode, $base))
                    ->send($method, $path, array_filter([
                        'query' => $query ?: null,
                        'json' => $json,
                    ], fn ($v) => $v !== null));
            } catch (ConnectionException) {
                if ($try >= $attempts) {
                    throw new QuinosRequestException('Could not reach Quinos (connection failed).');
                }

                $this->backoff($try);

                continue;
            }

            $this->absorbCookies($response);

            if ($response->serverError() && $try < $attempts) {
                $this->backoff($try);

                continue;
            }

            return $response;
        }
    }

    private function headers(string $mode, string $base): array
    {
        $headers = ['Accept' => 'text/html'];

        if ($mode !== 'html') {
            $headers['X-Requested-With'] = 'XMLHttpRequest';
            $headers['Origin'] = $base;
            $headers['Referer'] = $base.'/';
        }

        if ($mode === 'json') {
            $headers['Accept'] = 'application/json';
        }

        if ($mode === 'inertia') {
            $headers['Accept'] = 'text/html, application/xhtml+xml';
            $headers['X-Inertia'] = 'true';

            if ($this->version !== null) {
                $headers['X-Inertia-Version'] = $this->version;
            }
        }

        if (isset($this->cookies['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = urldecode($this->cookies['XSRF-TOKEN']);
        }

        if ($this->cookies !== []) {
            $headers['Cookie'] = collect($this->cookies)
                ->map(fn ($value, $name) => $name.'='.$value)
                ->implode('; ');
        }

        return $headers;
    }

    private function absorbCookies(Response $response): void
    {
        foreach ($response->toPsrResponse()->getHeader('Set-Cookie') as $line) {
            try {
                $cookie = SymfonyCookie::fromString($line);
            } catch (InvalidArgumentException) {
                continue;
            }

            $expired = $cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time();

            if ($expired || $cookie->getValue() === null || $cookie->getValue() === '' || $cookie->getValue() === 'deleted') {
                unset($this->cookies[$cookie->getName()]);

                continue;
            }

            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }
    }

    private function decodePage(Response $response, string $path): QuinosPage
    {
        $data = $response->json();

        if (! is_array($data) || ! isset($data['component'])) {
            // The server ignored X-Inertia and sent the HTML shell: read its data-page.
            $data = $this->pageDataFromHtml($response->body());
        }

        if (! is_array($data) || ! isset($data['component'])) {
            throw new QuinosRequestException("Quinos did not return a page for {$path}.", $response->status());
        }

        return QuinosPage::fromArray($data);
    }

    private function pageDataFromHtml(string $html): ?array
    {
        if (! preg_match('/data-page="([^"]*)"/', $html, $m)) {
            return null;
        }

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function versionFromHtml(string $html): ?string
    {
        $version = $this->pageDataFromHtml($html)['version'] ?? null;

        return filled($version) ? (string) $version : null;
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function isLoginRedirect(Response $response): bool
    {
        if (! $this->isRedirect($response)) {
            return false;
        }

        $path = parse_url((string) $response->header('Location'), PHP_URL_PATH);

        return is_string($path) && rtrim($path, '/') === '/login';
    }

    private function isSessionExpired(Response $response): bool
    {
        return in_array($response->status(), [401, 419], true) || $this->isLoginRedirect($response);
    }

    /** Path + query of a URL on the Quinos host, or null for anything else. */
    private function localPath(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $baseHost = parse_url(config('quinos.base_url'), PHP_URL_HOST);

        if (isset($parts['host']) && $parts['host'] !== $baseHost) {
            return null;
        }

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function throttle(): void
    {
        $wait = $this->lastRequestAt + config('quinos.delay_ms') / 1000 - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        $this->lastRequestAt = microtime(true);
    }

    private function backoff(int $try): void
    {
        usleep((int) config('quinos.retry_delay_ms') * 1000 * $try);
    }

    // ----------------------------------------------------- session storage

    private function loadSession(): void
    {
        if ($this->sessionLoaded) {
            return;
        }

        $this->sessionLoaded = true;

        $stored = QuinosSession::where('client_id', $this->client->id)->first();

        if ($stored !== null) {
            $this->cookies = $stored->cookies ?? [];
            $this->version = $stored->inertia_version;
        }
    }

    private function persist(bool $loggedIn = false): void
    {
        $session = QuinosSession::firstOrNew(['client_id' => $this->client->id]);

        $session->cookies = $this->cookies;
        $session->inertia_version = $this->version;

        if ($loggedIn) {
            $session->logged_in_at = now();
        }

        if ($session->isDirty()) {
            $session->save();
        }
    }
}
