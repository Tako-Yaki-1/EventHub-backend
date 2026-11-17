<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_a_trailing_slash_in_frontend_url_is_normalized(): void
    {
        $original = [
            'server' => $_SERVER['FRONTEND_URL'] ?? null,
            'env' => $_ENV['FRONTEND_URL'] ?? null,
        ];

        $_SERVER['FRONTEND_URL'] = 'https://eventhub.vercel.app/';
        $_ENV['FRONTEND_URL'] = 'https://eventhub.vercel.app/';

        try {
            $cors = require base_path('config/cors.php');
        } finally {
            $this->restore($_SERVER, 'FRONTEND_URL', $original['server']);
            $this->restore($_ENV, 'FRONTEND_URL', $original['env']);
        }

        $this->assertSame(['https://eventhub.vercel.app'], $cors['allowed_origins']);
    }

    public function test_an_allowed_frontend_origin_gets_cors_headers(): void
    {
        config(['cors.allowed_origins' => ['https://eventhub.vercel.app']]);

        $this->withHeaders(['Origin' => 'https://eventhub.vercel.app'])
            ->getJson('/api/v1/events')
            ->assertHeader('Access-Control-Allow-Origin', 'https://eventhub.vercel.app');
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function restore(array &$source, string $key, ?string $value): void
    {
        if ($value === null) {
            unset($source[$key]);

            return;
        }

        $source[$key] = $value;
    }
}
