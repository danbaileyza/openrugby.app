<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            $table->index('generated_at');
            $table->index(['source_type', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            $table->dropIndex(['generated_at']);
            $table->dropIndex(['source_type', 'generated_at']);
        });
    }
};
