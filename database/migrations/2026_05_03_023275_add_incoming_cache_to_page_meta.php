<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('page_meta', function (Blueprint $table) {
            $table->dateTime('incoming_fetched_at')->nullable()->after('link_count');
            $table->unsignedInteger('incoming_link_count')->default(0)->after('incoming_fetched_at');

            $table->index('incoming_fetched_at', 'idx_incoming_fetched');
        });
    }

    public function down(): void
    {
        Schema::table('page_meta', function (Blueprint $table) {
            $table->dropIndex('idx_incoming_fetched');
            $table->dropColumn(['incoming_fetched_at', 'incoming_link_count']);
        });
    }
};