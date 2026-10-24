<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_login_accounts', function (Blueprint $table) {
            $table->string('customer_id', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Only safe when no rows have a NULL customer_id.
        Schema::table('google_login_accounts', function (Blueprint $table) {
            $table->string('customer_id', 20)->nullable(false)->change();
        });
    }
};
