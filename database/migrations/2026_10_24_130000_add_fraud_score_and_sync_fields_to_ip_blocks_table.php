<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_blocks', function (Blueprint $table) {
            $table->unsignedSmallInteger('fraud_score')->nullable()->after('ip_address');
            $table->boolean('is_subnet')->default(false)->after('fraud_score');
            $table->timestamp('synced_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ip_blocks', function (Blueprint $table) {
            $table->dropColumn(['fraud_score', 'is_subnet', 'synced_at']);
        });
    }
};
