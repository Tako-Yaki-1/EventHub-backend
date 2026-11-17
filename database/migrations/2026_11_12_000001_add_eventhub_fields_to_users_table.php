<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('student_id', 30)->nullable()->unique()->after('id');
            $table->enum('role', ['student', 'admin'])->default('student')->after('password');
            $table->string('year_section', 30)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['student_id']);
            $table->dropColumn(['student_id', 'role', 'year_section']);
        });
    }
};
