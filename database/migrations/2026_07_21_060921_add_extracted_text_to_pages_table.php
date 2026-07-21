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
            $table->text('extracted_text')
                  ->nullable()
                  ->collation('utf8mb4_unicode_ci')
                  ->after('footer_data');

            // 2. Criar o índice Full-Text nativo
            // Damos um nome customizado ao índice (segundo parâmetro) para facilitar o drop na reversão
            $table->fullText('extracted_text', 'idx_pages_extracted_text_fulltext');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // No MySQL, deves SEMPRE remover o índice antes de remover a coluna
            $table->dropFullText('idx_pages_extracted_text_fulltext');
            $table->dropColumn('extracted_text');
        });
    }
};
