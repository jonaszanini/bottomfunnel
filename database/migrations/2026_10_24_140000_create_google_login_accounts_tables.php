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
        Schema::create('google_login_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->text('password'); // Encrypted string using GOOGLE_ACCOUNTS_ENCRYPTION_KEY
            $table->string('customer_id', 20)->nullable(); // Optional 10-digit Google Ads account ID
            $table->string('login_customer_id', 20)->nullable(); // Optional MCC manager customer ID
            $table->string('status', 32)->default('active'); // 'active', 'cancelled', 'suspended'
            $table->char('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('linked_google_ads_account_id')
                ->nullable()
                ->constrained('google_ads_accounts')
                ->nullOnDelete();
            $table->timestamps();

            $table->index('email');
            $table->index('customer_id');
            $table->index('status');
        });

        Schema::create('google_login_account_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_login_account_id')
                ->constrained('google_login_accounts')
                ->cascadeOnDelete();
            $table->string('campaign_id', 64);
            $table->string('campaign_name', 255)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['google_login_account_id', 'campaign_id'], 'gla_campaign_unique');
            $table->index('campaign_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_login_account_campaigns');
        Schema::dropIfExists('google_login_accounts');
    }
};
