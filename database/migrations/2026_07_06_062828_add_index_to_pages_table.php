<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pages', function (Blueprint $table) {
            // 🚀 O ESCUDO ANTI-ENGASGO: Ensina o MySQL a pré-ordenar as páginas!
            $table->index(['notebook_id', 'page_number']);
        });
    }

    public function down()
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['notebook_id', 'page_number']);
        });
    }
};