<?php

namespace Tests\Feature;

use App\Lib\PasarGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasarGuardClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::put('pasarguard-token-7', 'stale-token');
    }

    private function client(): PasarGuard
    {
        return new PasarGuard(['id' => 7, 'url' => 'https://panel.test', 'username' => 'admin', 'password' => 'secret']);
    }

    public function test_expired_token_is_refreshed_once_and_the_original_write_is_replayed(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fresh-token']),
            '*/api/user/by-id/42' => Http::sequence()->push(['detail' => 'Invalid token'], 401)
                ->push(['id' => 42, 'status' => 'active']),
        ]);
        $result = $this->client()->updateUserById(42, ['data_limit' => 100]);
        $this->assertSame(42, $result['id']);
        $this->assertSame('fresh-token', Cache::get('pasarguard-token-7'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->hasHeader('Authorization', 'Bearer fresh-token') && $request['data_limit'] === 100);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/token')
            && $request['username'] === 'admin' && $request['password'] === 'secret'
            && !$request->hasHeader('Authorization'));
    }

    public function test_second_unauthorized_response_stops_and_clears_the_bad_token(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'also-invalid']),
            '*/api/user/*' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);
        $result = $this->client()->getUserById(42);
        $this->assertFalse($result['status']);
        $this->assertSame(401, $result['http_code']);
        $this->assertNull(Cache::get('pasarguard-token-7'));
        Http::assertSentCount(3);
    }

    public function test_login_failure_is_returned_without_replaying_a_write(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['detail' => 'Incorrect credentials'], 401),
            '*/api/user' => Http::response(['detail' => 'Expired'], 401),
        ]);
        $result = $this->client()->createUser(['username' => 'test-user']);
        $this->assertFalse($result['status']);
        $this->assertSame('Incorrect credentials', $result['message']);
        Http::assertSentCount(2);
    }

    public function test_server_errors_and_timeouts_do_not_repeat_a_write(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'Internal error'], 500)]);
        $this->assertFalse($this->client()->createUser(['username' => 'test-user'])['status']);
        Http::assertSentCount(1);
    }

    public function test_connection_failure_returns_an_explicit_failure(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->assertFalse($this->client()->createUser(['username' => 'test-user'])['status']);
    }

    public function test_validation_errors_include_fields_but_exclude_submitted_input(): void
    {
        Http::fake(['*' => Http::response(['detail' => [
            ['loc' => ['body', 'on_hold_timeout'], 'msg' => 'Invalid datetime', 'input' => 'private-input'],
        ]], 422)]);
        $result = $this->client()->createUser(['username' => 'test-user']);
        $this->assertSame('body.on_hold_timeout: Invalid datetime', $result['message']);
        $this->assertSame(422, $result['http_code']);
        $this->assertStringNotContainsString('private-input', json_encode($result));
    }

    public function test_on_hold_creation_uses_seconds_and_keeps_subscription_when_links_fail(): void
    {
        Http::fake([
            '*/api/user' => Http::response(['id' => 42, 'status' => 'on_hold', 'subscription_url' => 'https://sub.test/42']),
            '*/subscription/links' => Http::response(['detail' => 'Unavailable'], 503),
        ]);
        $result = $this->client()->createUserAndGetConfig(['username' => 'test-user', 'group_id' => ['2'], 'days' => 30, 'status' => 'on_hold']);
        $this->assertTrue($result['status']);
        $this->assertSame('', $result['config']);
        $this->assertSame('https://sub.test/42', $result['subscription_url']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['on_hold_expire_duration'] === 2592000 && $request['expire'] === 0
            && $request['group_ids'] === [2] && str_contains($request->body(), '"proxy_settings":{}'));
    }

    public function test_active_creation_can_omit_status(): void
    {
        Http::fake([
            '*/api/user' => Http::response(['id' => 42, 'status' => 'active', 'subscription_url' => '/sub/42']),
            '*/subscription/links' => Http::response('vless://config', 200, ['Content-Type' => 'text/plain']),
        ]);
        $result = $this->client()->createUserAndGetConfig(['username' => 'test-user', 'group_id' => 2]);
        $this->assertTrue($result['status']);
        $this->assertSame('vless://config', $result['config']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['status'] === 'active'
            && str_ends_with($request['expire'], 'Z'));
    }
}
