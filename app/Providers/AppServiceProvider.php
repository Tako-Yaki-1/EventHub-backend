<?php

namespace App\Providers;

use App\Contracts\AiClient;
use App\Models\User;
use App\Services\Ai\AnthropicDriver;
use App\Services\Ai\FakeAiDriver;
use App\Services\Ai\NullAiDriver;
use App\Services\Ai\OpenAiDriver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The fake driver is only selected when AI_DRIVER=fake (tests).
        $this->app->singleton(FakeAiDriver::class);

        $this->app->singleton(AiClient::class, fn ($app) => match (config('ai.driver')) {
            'fake' => $app->make(FakeAiDriver::class),
            'anthropic' => new AnthropicDriver,
            'openai' => new OpenAiDriver,
            default => new NullAiDriver,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(
            fn (User $user, string $token) => config('app.frontend_url')
                .'/reset-password?token='.$token.'&email='.urlencode($user->email)
        );
    }
}
