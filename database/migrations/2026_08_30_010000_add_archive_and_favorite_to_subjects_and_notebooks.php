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
        // 1. Atualizar tabela de Disciplinas (Subjects)
        Schema::table('subjects', function (Blueprint $table) {
            if (!Schema::hasColumn('subjects', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->after('icon');
            }
            if (!Schema::hasColumn('subjects', 'is_favorite')) {
                $table->boolean('is_favorite')->default(false)->after('is_archived');
            }
        });

        // 2. Atualizar tabela de Cadernos (Notebooks)
        Schema::table('notebooks', function (Blueprint $table) {
            if (!Schema::hasColumn('notebooks', 'tags')) {
                $table->json('tags')->nullable()->after('description');
            }
            if (!Schema::hasColumn('notebooks', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->after('tags');
            }
            if (!Schema::hasColumn('notebooks', 'is_favorite')) {
                $table->boolean('is_favorite')->default(false)->after('is_archived');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['is_archived', 'is_favorite']);
        });

        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn(['tags', 'is_archived', 'is_favorite']);
        });
    }
};
