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
        Schema::table('notebooks', function (Blueprint $table) {
            $table->string('alternative_title')->nullable()->after('title');
            $table->string('sharing_type')->default('full')->after('alternative_title');
            $table->boolean('notifications_enabled')->default(true)->after('last_updated_by_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn(['alternative_title', 'sharing_type', 'notifications_enabled']);
        });
    }
};
