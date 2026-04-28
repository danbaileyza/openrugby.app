<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic favourites — one table covers teams, players, competitions.
 * Keyed by (user_id, favouritable_type, favouritable_id) so toggling on/off
 * is a single delete/insert without sequence games.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favourites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('favouritable_type');
            // UUIDs (36 chars) and ULIDs (26 chars) coexist as IDs across the
            // schema, so use string(36) to fit the widest case.
            $table->string('favouritable_id', 36);
            $table->timestamps();

            $table->unique(['user_id', 'favouritable_type', 'favouritable_id'], 'favourites_unique');
            $table->index(['favouritable_type', 'favouritable_id'], 'favourites_morph_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favourites');
    }
};
