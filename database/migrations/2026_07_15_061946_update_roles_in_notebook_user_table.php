<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 🚀 Altera o ENUM nativo do MySQL para suportar os novos papéis EdTech
        DB::statement("ALTER TABLE notebook_user MODIFY COLUMN role ENUM('owner', 'editor', 'viewer', 'student') NOT NULL DEFAULT 'viewer'");
    }

    public function down(): void
    {
        // Reverte para o ENUM antigo de dois papéis
        DB::statement("ALTER TABLE notebook_user MODIFY COLUMN role ENUM('viewer', 'editor') NOT NULL DEFAULT 'viewer'");
    }
};