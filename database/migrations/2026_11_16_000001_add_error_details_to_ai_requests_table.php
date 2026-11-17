<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            // The provider's HTTP status and a short sanitized message, for the admin page.
            $table->unsignedSmallInteger('http_status')->nullable()->after('error_code');
            $table->string('error_message', 200)->nullable()->after('http_status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropColumn(['http_status', 'error_message']);
        });
    }
};
