<?php

namespace Tests\Feature\Quinos;

use App\Exceptions\Quinos\QuinosAuthException;
use App\Exceptions\Quinos\QuinosRequestException;
use App\Models\Client;
use App\Models\QuinosSession;
use App\Services\Quinos\QuinosClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuinos;
use Tests\TestCase;

class QuinosClientTest extends TestCase
{
    use RefreshDatabase;

    private FakeQuinos $quinos;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quinos = FakeQuinos::install();
        $this->client = Client::factory()->create([
            'quinos_email' => $this->quinos->email,
            'quinos_password' => $this->quinos->password,
        ]);
    }

    private function quinos(): QuinosClient
    {
        return QuinosClient::for($this->client->fresh());
    }

    // ----------------------------------------------------------------- login

    public function test_login_signs_in_and_reports_the_company(): void
    {
        $page = $this->quinos()->login();

        $this->assertSame('Zali Uluwatu', $page->companyName);
        $this->assertSame('224', $page->companyId);
        $this->assertSame(1, $this->quinos->loginCount);
        $this->assertNotNull($this->client->fresh()->last_login_at);
    }

    public function test_login_posts_the_csrf_header_and_credentials(): void
    {
        $this->quinos()->login();

        $post = $this->quinos->requests('POST', '/login')[0];

        $this->assertSame('xsrf-token-1==', $post['headers']['X-XSRF-TOKEN'][0], 'the url-decoded CSRF cookie is sent back as a header');
        $this->assertSame($this->quinos->email, $post['body']['email']);
        $this->assertStringContainsString('application/json', $post['headers']['Accept'][0]);
    }

    public function test_pages_drop_the_auth_and_ziggy_props(): void
    {
        $page = $this->quinos()->login();

        $this->assertArrayNotHasKey('auth', $page->props);
        $this->assertArrayNotHasKey('ziggy', $page->props);
        $this->assertStringNotContainsString('FAKE-PIN', json_encode($page));
        $this->assertStringNotContainsString('FAKE-TOKEN', json_encode($page));
    }

    public function test_the_session_is_stored_encrypted(): void
    {
        $this->quinos()->login();

        $session = QuinosSession::where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame('v1', $session->inertia_version);
        $this->assertSame('sess-1', $session->cookies['quinos_session']);

        $raw = DB::table('quinos_sessions')->where('client_id', $this->client->id)->value('cookies');
        $this->assertStringNotContainsString('sess-1', $raw);
        $this->assertStringNotContainsString('quinos_session', $raw);
    }

    public function test_wrong_password_is_reported_without_leaking_it(): void
    {
        $this->client->update(['quinos_password' => 'definitely-wrong']);

        try {
            $this->quinos()->login();
            $this->fail('Expected a QuinosAuthException');
        } catch (QuinosAuthException $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
            $this->assertStringNotContainsString('definitely-wrong', $e->getMessage());
        }
    }

    public function test_login_needs_saved_credentials(): void
    {
        $this->client->update(['quinos_email' => null, 'quinos_password' => null]);

        $this->expectException(QuinosAuthException::class);
        $this->expectExceptionMessage('no Quinos email and password');

        $this->quinos()->login();
    }

    public function test_a_stale_csrf_token_is_retried_once(): void
    {
        $this->quinos->csrfFailuresLeft = 1;

        $this->quinos()->login();

        $this->assertCount(2, $this->quinos->requests('GET', '/login'));
        $this->assertSame(1, $this->quinos->loginCount);
    }

    public function test_rate_limited_logins_stop_with_a_clear_message(): void
    {
        $this->quinos->rateLimited = true;

        $this->expectException(QuinosAuthException::class);
        $this->expectExceptionMessage('rate limiting');

        $this->quinos()->login();
    }

    public function test_a_login_that_never_becomes_a_session_is_explained(): void
    {
        $this->quinos->sessionNeverValid = true;

        $this->expectException(QuinosAuthException::class);
        $this->expectExceptionMessage('captcha');

        $this->quinos()->login();
    }

    // -------------------------------------------------------------- sessions

    public function test_a_stored_session_is_reused_without_logging_in_again(): void
    {
        $this->quinos()->login();

        $page = $this->quinos()->get('/salesTypes'); // brand new instance

        $this->assertCount(2, $page->rows());
        $this->assertSame(1, $this->quinos->loginCount);
    }

    public function test_the_first_request_logs_in_automatically(): void
    {
        $page = $this->quinos()->get('/salesTypes');

        $this->assertSame('DINE IN', $page->rows()[0]['name']);
        $this->assertSame(1, $this->quinos->loginCount);
    }

    public function test_an_expired_session_logs_in_again_and_retries(): void
    {
        $quinos = $this->quinos();
        $quinos->login();
        $this->quinos->expireSession();

        $page = $quinos->get('/salesTypes');

        $this->assertCount(2, $page->rows());
        $this->assertSame(2, $this->quinos->loginCount);
    }

    public function test_a_changed_page_version_is_refreshed_without_a_new_login(): void
    {
        $quinos = $this->quinos();
        $quinos->login();
        $this->quinos->version = 'v2';

        $page = $quinos->get('/salesTypes');

        $this->assertCount(2, $page->rows());
        $this->assertSame(1, $this->quinos->loginCount);
        $this->assertSame('v2', QuinosSession::first()->inertia_version);
    }

    // ------------------------------------------------------------ pagination

    public function test_rows_walks_every_page_and_keeps_the_filters(): void
    {
        $codes = collect(iterator_to_array($this->quinos()->rows('/items', ['search' => '', 'type' => '']), false))->pluck('code')->all();

        $this->assertSame(['A', 'B', 'C'], $codes);

        $itemRequests = $this->quinos->requests('GET', '/items');
        $this->assertSame([null, 'c2', 'c3'], array_map(fn ($r) => $r['query']['cursor'] ?? null, $itemRequests));
        $this->assertSame('', $itemRequests[1]['query']['search']);
    }

    public function test_pagination_that_loops_is_stopped(): void
    {
        $this->quinos->itemPages = [
            '' => ['rows' => [['code' => 'A']], 'next' => 'c2'],
            'c2' => ['rows' => [['code' => 'B']], 'next' => 'c2'],
        ];

        $this->expectException(QuinosRequestException::class);
        $this->expectExceptionMessage('circle');

        iterator_to_array($this->quinos()->rows('/items'));
    }

    public function test_pagination_has_a_page_limit(): void
    {
        config(['quinos.max_pages' => 2]);

        $this->expectException(QuinosRequestException::class);
        $this->expectExceptionMessage('Stopped after 2 pages');

        iterator_to_array($this->quinos()->rows('/items'));
    }

    // ----------------------------------------------------------- POST + errors

    public function test_post_sends_json_with_the_csrf_header(): void
    {
        $page = $this->quinos()->post('/reports/pos/transactionDetailReport', [
            'from' => '2026-10-01', 'to' => '2026-10-01', 'outlet_id' => null,
        ]);

        $this->assertSame('INV-000001', $page->props['result'][0]['invoice_no']);

        $post = $this->quinos->requests('POST', '/reports/pos/transactionDetailReport')[0];
        $this->assertSame('2026-10-01', $post['body']['from']);
        $this->assertNotEmpty($post['headers']['X-XSRF-TOKEN'][0]);
        $this->assertSame('true', $post['headers']['X-Inertia'][0]);
    }

    public function test_server_errors_are_retried(): void
    {
        $quinos = $this->quinos();
        $quinos->login();
        $this->quinos->serverErrorsLeft = 2;

        $page = $quinos->get('/salesTypes');

        $this->assertCount(2, $page->rows());
        // 1 check after login + 2 failures + 1 success.
        $this->assertCount(4, $this->quinos->requests('GET', '/salesTypes'));
    }

    public function test_persistent_server_errors_give_up(): void
    {
        $quinos = $this->quinos();
        $quinos->login();
        $this->quinos->serverErrorsLeft = 50;

        $this->expectException(QuinosRequestException::class);
        $this->expectExceptionMessage('HTTP 500');

        $quinos->get('/salesTypes');
    }

    public function test_an_unreachable_server_gives_a_clear_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: secret-host-detail'));

        try {
            $this->quinos()->login();
            $this->fail('Expected a QuinosRequestException');
        } catch (QuinosRequestException $e) {
            $this->assertStringContainsString('Could not reach Quinos', $e->getMessage());
            $this->assertStringNotContainsString('secret-host-detail', $e->getMessage());
        }
    }

    public function test_cookies_and_passwords_never_appear_in_error_messages(): void
    {
        $quinos = $this->quinos();
        $quinos->login();
        $this->quinos->serverErrorsLeft = 50;

        try {
            $quinos->get('/salesTypes');
        } catch (QuinosRequestException $e) {
            foreach (['sess-1', 'xsrf-token', $this->quinos->password] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }
}
