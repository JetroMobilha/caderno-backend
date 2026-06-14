<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Adicionar Ícone às Disciplinas
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('icon')->nullable()->after('color'); // Pode guardar o nome de um ícone do Flutter (ex: 'book_icon')
        });

        // 2. Adicionar Cor e Imagem aos Cadernos
        Schema::table('notebooks', function (Blueprint $table) {
            $table->string('color')->nullable()->after('cover_type');
            $table->string('cover_image')->nullable()->after('color'); // Pode ser um URL ou o nome de uma imagem local
        });

        // 3. Adicionar Cabeçalho e Rodapé às Páginas
        Schema::table('pages', function (Blueprint $table) {
            // Usamos JSON caso o cabeçalho/rodapé também possam ter desenhos (strokes) ou formatação rica
            $table->json('header_data')->nullable()->after('page_number');
            $table->json('footer_data')->nullable()->after('stroke_data');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('icon');
        });

        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn(['color', 'cover_image']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['header_data', 'footer_data']);
        });
    }
};