<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->boolean('presell_hop')->default(false)->after('converted');
            $table->timestamp('hopped_at')->nullable()->after('presell_hop');
            $table->index(['track_id', 'presell_hop']);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex(['track_id', 'presell_hop']);
            $table->dropColumn(['presell_hop', 'hopped_at']);
        });
    }
};
