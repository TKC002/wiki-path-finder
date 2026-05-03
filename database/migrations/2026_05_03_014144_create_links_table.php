<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->unsignedInteger('source_id');
            $table->unsignedInteger('target_id');

            $table->primary(['source_id', 'target_id']);
            $table->index('target_id', 'idx_target');

            $table->foreign('source_id')
                  ->references('id')->on('pages')
                  ->cascadeOnDelete();
            $table->foreign('target_id')
                  ->references('id')->on('pages')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};