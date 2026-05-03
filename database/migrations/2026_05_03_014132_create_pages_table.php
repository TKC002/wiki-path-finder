<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->unsignedInteger('id', true);            // AUTO_INCREMENT
            $table->string('title', 255);
            $table->unique('title', 'uniq_title');
        });

        // utf8mb4_bin にするための調整(タイトルの厳密一致が必要)
        \DB::statement('ALTER TABLE pages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};