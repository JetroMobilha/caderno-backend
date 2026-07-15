<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // 💰 Injeta os rastreadores de produto comprado
            $table->string('item_type')->default('subscription')->after('status'); // 'subscription' ou 'notebook'
            $table->unsignedBigInteger('item_id')->nullable()->after('item_type'); // ID do caderno, caso seja do tipo notebook
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['item_type', 'item_id']);
        });
    }
};