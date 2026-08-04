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
        Schema::table('pages', function (Blueprint $table) {
            // 1. Garantir que a coluna usa utf8mb4 (suporta acentos perfeitos e emojis)
            $column = $table->text('extracted_text')
                  ->nullable()
                  ->after('footer_data');

            if (DB::getDriverName() !== 'sqlite') {
                $column->collation('utf8mb4_unicode_ci');
            }

            // 2. Criar o índice Full-Text nativo (apenas para MySQL)
            if (DB::getDriverName() === 'mysql') {
                $table->fullText('extracted_text', 'idx_pages_extracted_text_fulltext');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // No MySQL, deves SEMPRE remover o índice antes de remover a coluna
            if (DB::getDriverName() === 'mysql') {
                $table->dropFullText('idx_pages_extracted_text_fulltext');
            }
            $table->dropColumn('extracted_text');
        });
    }
};
