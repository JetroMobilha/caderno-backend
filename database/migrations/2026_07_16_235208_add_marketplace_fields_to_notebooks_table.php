<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            // Rastreia se este caderno foi clonado da loja (e de qual ID original)
            $table->foreignId('original_notebook_id')->nullable()->constrained('notebooks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropForeign(['original_notebook_id']);
            $table->dropColumn(['is_published', 'price', 'description', 'author_name', 'original_notebook_id']);
        });
    }
};