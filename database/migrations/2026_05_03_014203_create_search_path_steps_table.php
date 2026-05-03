<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('search_path_steps', function (Blueprint $table) {
            $table->unsignedBigInteger('history_id');
            $table->unsignedTinyInteger('step_index');
            $table->unsignedInteger('page_id');

            $table->primary(['history_id', 'step_index']);
            $table->index('page_id', 'idx_page');

            $table->foreign('history_id')
                  ->references('id')->on('search_history')
                  ->cascadeOnDelete();
            $table->foreign('page_id')->references('id')->on('pages');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_path_steps');
    }
};