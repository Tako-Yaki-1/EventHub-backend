<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_audit_log_stores_the_forwarded_client_ip(): void
    {
        User::factory()->create([
            'email' => 'proxy@example.com',
            'password' => 'secret-pass',
        ]);

        $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
        ])->postJson('/api/v1/auth/login', [
            'login' => 'proxy@example.com',
            'password' => 'secret-pass',
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'auth.login')->firstOrFail();

        $this->assertSame('203.0.113.9', $log->ip_address);
    }

    public function test_the_forwarded_proto_makes_generated_urls_https(): void
    {
        Route::get('/proxy-probe', fn () => response()->json([
            'secure' => request()->isSecure(),
            'url' => url('/proxy-probe'),
        ]));

        $body = $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->getJson('/proxy-probe')
            ->assertOk()
            ->assertJson(['secure' => true])
            ->json();

        $this->assertStringStartsWith('https://', $body['url']);
    }
}
