<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $blueprint) {
            // Adiciona a coluna 'deleted_at'
            $blueprint->softDeletes(); 
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $blueprint) {
            $blueprint->dropSoftDeletes();
        });
    }
};