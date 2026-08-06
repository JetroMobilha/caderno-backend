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
        Schema::table('subjects', function (Blueprint $table) {
            if (!Schema::hasColumn('subjects', 'updated_at_ms')) {
                $table->bigInteger('updated_at_ms')->nullable()->after('client_id');
            }
        });

        Schema::table('notebooks', function (Blueprint $table) {
            if (!Schema::hasColumn('notebooks', 'updated_at_ms')) {
                $table->bigInteger('updated_at_ms')->nullable()->after('client_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            if (Schema::hasColumn('subjects', 'updated_at_ms')) {
                $table->dropColumn('updated_at_ms');
            }
        });

        Schema::table('notebooks', function (Blueprint $table) {
            if (Schema::hasColumn('notebooks', 'updated_at_ms')) {
                $table->dropColumn('updated_at_ms');
            }
        });
    }
};
