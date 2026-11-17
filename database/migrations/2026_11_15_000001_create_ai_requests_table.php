<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 32);
            $table->string('driver', 32)->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->boolean('tokens_estimated')->default(false);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->enum('status', ['success', 'error', 'blocked', 'rate_limited', 'fallback']);
            $table->string('error_code', 40)->nullable();
            $table->unsignedInteger('prompt_chars')->default(0);
            $table->string('prompt_hash', 64);
            // Only written when AI_LOG_PROMPTS=true.
            $table->text('prompt')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['feature', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
