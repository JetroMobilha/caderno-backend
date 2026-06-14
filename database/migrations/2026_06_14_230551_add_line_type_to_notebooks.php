<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            // Pode guardar 'blank', 'ruled', 'grid', 'dotted', etc.
            $table->string('line_type')->nullable()->after('cover_image'); 
        });
    }

    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn('line_type');
        });
    }
};
