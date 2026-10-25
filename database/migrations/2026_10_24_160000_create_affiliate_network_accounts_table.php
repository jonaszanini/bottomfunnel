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
        Schema::create('affiliate_network_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('network', 32);
            $table->string('network_custom', 100)->nullable();
            $table->string('nickname', 255);
            $table->string('email', 255)->nullable();
            $table->string('username', 255)->nullable();
            $table->text('password'); // Encrypted string using GOOGLE_ACCOUNTS_ENCRYPTION_KEY / GoogleAccountPasswordCast
            $table->string('account_id', 100)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('login_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->char('default_currency', 3)->nullable();
            $table->string('payment_method_hint', 255)->nullable();
            $table->timestamps();

            $table->index('network');
            $table->index('status');
            $table->index('nickname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_network_accounts');
    }
};
