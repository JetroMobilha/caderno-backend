<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('avatar');
            $table->string('institution')->nullable()->after('bio');
            $table->string('preferred_color')->nullable()->after('institution');
            $table->string('preferred_font')->nullable()->after('preferred_color');
            $table->text('specialties')->nullable()->after('preferred_font');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bio', 'institution', 'preferred_color', 'preferred_font', 'specialties']);
        });
    }
};
