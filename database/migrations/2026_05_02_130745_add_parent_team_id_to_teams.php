<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schools field multiple squads — Grey College's senior 1st XV is a
 * different team from Grey College "Cherries" (the 2nd XV) but they
 * belong to the same school. This adds a self-referencing parent_team_id
 * so a sub-squad can link to its parent without losing distinct stats.
 *
 * Nullable; left null means "this team is its own parent".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignUuid('parent_team_id')
                ->nullable()
                ->after('id')
                ->constrained('teams')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['parent_team_id']);
            $table->dropColumn('parent_team_id');
        });
    }
};
