<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            // Adiciona client_id após o ID, único e com índice para buscas rápidas
            $table->string('client_id')->nullable()->unique()->after('id');
        });

        Schema::table('notebooks', function (Blueprint $table) {
            $table->string('client_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('client_id');
        });

        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn('client_id');
        });
    }
};