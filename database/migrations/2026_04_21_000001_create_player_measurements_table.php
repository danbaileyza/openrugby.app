<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_measurements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('player_id')->constrained()->cascadeOnDelete();
            $table->integer('height_cm')->nullable();
            $table->integer('weight_kg')->nullable();
            $table->date('recorded_at');
            $table->enum('source', ['manual', 'on_edit', 'imported'])->default('manual');
            $table->text('notes')->nullable();
            $table->foreignUuid('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['player_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_measurements');
    }
};
