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
        Schema::table('payments', function (Blueprint $table) {
            // 1. Adicionar os novos campos
            $table->string('plan_type')->default('pro_monthly')->after('status');
            $table->dateTime('expires_at')->nullable()->after('plan_type');
            $table->dateTime('paid_at')->nullable()->after('expires_at');
            
            // 2. Garantir que a coluna reference é única (se já tiver dados duplicados na BD, isto vai dar erro, mas se estiver vazia funciona perfeito)
            $table->unique('reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropUnique('payments_reference_unique');
            $table->dropColumn(['plan_type', 'expires_at', 'paid_at']);
             
        });
    }
};
