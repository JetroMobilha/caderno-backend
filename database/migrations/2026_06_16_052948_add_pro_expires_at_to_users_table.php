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
        Schema::table('users', function (Blueprint $table) {
            // Guarda a data até à qual o utilizador tem o Plano Pro ativo. Se for nulo ou data passada, é Free.
            $table->dateTime('pro_expires_at')->nullable()->after('password');
        });
    }
    /**
     * Reverse the migrations.
     */
   public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pro_expires_at');
        });
    }
};
