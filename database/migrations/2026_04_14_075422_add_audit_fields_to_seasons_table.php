<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->unsignedTinyInteger('completeness_score')->nullable()->after('is_current');
            $table->json('completeness_audit')->nullable()->after('completeness_score');
            $table->boolean('is_verified')->default(false)->after('completeness_audit');
            $table->timestamp('verified_at')->nullable()->after('is_verified');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn(['completeness_score', 'completeness_audit', 'is_verified', 'verified_at']);
        });
    }
};
