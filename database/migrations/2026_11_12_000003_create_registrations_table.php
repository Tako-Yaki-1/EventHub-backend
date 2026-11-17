<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['registered', 'cancelled'])->default('registered');
            $table->string('full_name', 100);
            $table->string('student_id', 30);
            $table->string('year_section', 30)->nullable();
            $table->string('email', 150);
            $table->boolean('confirmed_attendance')->default(false);
            $table->dateTime('registered_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_id']);
            $table->index('event_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
