<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('search_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('start_id');
            $table->unsignedInteger('goal_id');
            $table->unsignedTinyInteger('clicks')->nullable();
            $table->boolean('found')->default(false);
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('api_calls')->default(0);
            $table->unsignedInteger('visited_count')->default(0);
            $table->unsignedTinyInteger('max_depth_per_side')->default(3);
            $table->dateTime('searched_at');

            $table->index(['start_id', 'goal_id', 'searched_at'], 'idx_pair');
            $table->index('searched_at', 'idx_searched');

            $table->foreign('start_id')->references('id')->on('pages');
            $table->foreign('goal_id') ->references('id')->on('pages');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_history');
    }
};