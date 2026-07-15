<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            // 🛡️ Altera a restrição para aceitar NULL (necessário para cadernos partilhados soltos)
            $table->unsignedBigInteger('subject_id')->nullable()->change();

            // 🌟 Injeta os novos campos de monetização e autoria
            $table->boolean('is_published')->default(false)->after('paper_size');
            $table->decimal('price', 10, 2)->default(0.00)->after('is_published');
            $table->text('description')->nullable()->after('price');
            $table->string('author_name')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            // Reverte o subject_id para não nulo (pode falhar se houver registos nulos)
            $table->unsignedBigInteger('subject_id')->nullable(false)->change();

            // Remove os campos criados
            $table->dropColumn(['is_published', 'price', 'description', 'author_name']);
        });
    }
};