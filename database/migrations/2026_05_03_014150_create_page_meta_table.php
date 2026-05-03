<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('page_meta', function (Blueprint $table) {
            $table->unsignedInteger('page_id')->primary();
            $table->dateTime('wiki_touched_at')->nullable();
            $table->dateTime('fetched_at');
            $table->unsignedInteger('link_count')->default(0);

            $table->index('fetched_at', 'idx_fetched');

            $table->foreign('page_id')
                  ->references('id')->on('pages')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_meta');
    }
};