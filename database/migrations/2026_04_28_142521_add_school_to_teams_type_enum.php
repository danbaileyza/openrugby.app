<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * teams.type was created as an enum that didn't include 'school'.
 * Dev runs SQLite (no enum enforcement), so school teams imported fine
 * locally — but MySQL on staging/prod rejects the insert with a
 * "Data truncated for column 'type'" error.
 *
 * SQLite doesn't enforce enums, so this migration is a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE teams MODIFY COLUMN type ENUM("
            ."'club', 'national', 'franchise', 'provincial', 'invitational', 'school'"
            .") NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Down-migration would fail if any school rows exist — guard by
        // refusing rather than silently corrupting.
        $count = DB::table('teams')->where('type', 'school')->count();
        if ($count > 0) {
            throw new \RuntimeException(
                "Cannot remove 'school' from teams.type enum: {$count} row(s) still use it."
            );
        }

        DB::statement(
            "ALTER TABLE teams MODIFY COLUMN type ENUM("
            ."'club', 'national', 'franchise', 'provincial', 'invitational'"
            .") NOT NULL"
        );
    }
};
