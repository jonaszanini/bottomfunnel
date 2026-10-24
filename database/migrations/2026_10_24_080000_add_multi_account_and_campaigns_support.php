<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_ads_accounts', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_google_ads_account_id')
                ->nullable()
                ->after('password')
                ->constrained('google_ads_accounts')
                ->nullOnDelete();
        });

        Schema::create('google_ads_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_ads_account_id')->constrained()->cascadeOnDelete();
            $table->string('customer_id', 20);
            $table->string('campaign_id', 32);
            $table->string('name');
            $table->string('status', 32)->default('PAUSED');
            $table->unsignedBigInteger('budget_amount_micros')->nullable();
            $table->string('bidding_strategy', 32)->nullable();
            $table->string('resource_name')->nullable();
            $table->timestamps();

            $table->unique(['google_ads_account_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_campaigns');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['default_google_ads_account_id']);
            $table->dropColumn('default_google_ads_account_id');
        });

        Schema::table('google_ads_accounts', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
