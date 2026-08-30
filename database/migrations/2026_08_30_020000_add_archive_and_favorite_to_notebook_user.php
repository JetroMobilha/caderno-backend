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
        Schema::table('notebook_user', function (Blueprint $table) {
            if (!Schema::hasColumn('notebook_user', 'is_archived')) {
                $table->boolean('is_archived')->default(false);
            }
            if (!Schema::hasColumn('notebook_user', 'is_favorite')) {
                $table->boolean('is_favorite')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebook_user', function (Blueprint $table) {
            $table->dropColumn(['is_archived', 'is_favorite']);
        });
    }
};
