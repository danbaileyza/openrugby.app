<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Auth ─────────────────────────────────────────────

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['admin', 'team_user'])->default('team_user');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'team_id']);
        });

        // ─── Competition additions ────────────────────────────

        Schema::table('competitions', function (Blueprint $table) {
            $table->enum('level', ['professional', 'club', 'school'])->default('professional')->after('format');
            $table->string('grade')->nullable()->after('level'); // e.g. U16A, 1st XV
        });

        // ─── Match capture metadata ───────────────────────────

        Schema::table('matches', function (Blueprint $table) {
            $table->enum('score_source', ['imported', 'admin', 'team_user'])->default('imported')->after('status');
            $table->foreignUuid('captured_by_user_id')->nullable()->after('score_source')->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable()->after('captured_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropForeign(['captured_by_user_id']);
            $table->dropColumn(['score_source', 'captured_by_user_id', 'captured_at']);
        });

        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn(['level', 'grade']);
        });

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
