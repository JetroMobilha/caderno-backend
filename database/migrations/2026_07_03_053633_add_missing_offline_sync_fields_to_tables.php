<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Executar a missão de reforço das tabelas.
     */
    public function up(): void
    {
        // 1. Reforçar a tabela de Cadernos
        Schema::table('notebooks', function (Blueprint $table) {
            if (!Schema::hasColumn('notebooks', 'paper_size')) {
                // Adiciona o tamanho do papel (A4, A3, etc.) logo após o line_type
                $table->string('paper_size', 10)->default('A4')->after('line_type');
            }
        });

        // 2. Reforçar a tabela de Páginas
        Schema::table('pages', function (Blueprint $table) {
            if (!Schema::hasColumn('pages', 'is_landscape')) {
                // 0 = Retrato (Vertical), 1 = Paisagem (Horizontal)
                $table->boolean('is_landscape')->default(false)->after('page_number');
            }
            if (!Schema::hasColumn('pages', 'text_data')) {
                // Cofre JSON para os blocos de texto teclar
                $table->json('text_data')->nullable()->after('stroke_data');
            }
            if (!Schema::hasColumn('pages', 'image_data')) {
                // Cofre JSON para guardar os metadados e os LINKS (caminhos) das fotos
                $table->json('image_data')->nullable()->after('text_data');
            }
        });
    }

    /**
     * Reverter a operação em caso de retirada.
     */
    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn('paper_size');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['is_landscape', 'text_data', 'image_data']);
        });
    }
};