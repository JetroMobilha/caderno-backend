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
        Schema::create('lesson_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->onDelete('cascade');
            $table->string('client_id')->unique()->nullable();
            $table->string('title');
            $table->string('audio_url');
            $table->integer('duration_seconds')->default(0);
            $table->bigInteger('updated_at_ms')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_recordings');
    }
};
