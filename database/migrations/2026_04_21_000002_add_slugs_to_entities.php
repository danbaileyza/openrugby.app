<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('code')->index();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name')->index();
        });

        Schema::table('players', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('last_name')->index();
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('id')->index();
        });

        Schema::table('referees', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('last_name')->index();
        });
    }

    public function down(): void
    {
        foreach (['competitions', 'teams', 'players', 'matches', 'referees'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }
    }
};
