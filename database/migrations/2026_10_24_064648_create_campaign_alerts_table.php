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
        Schema::create('campaign_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32); // 'listing_down', 'bleeder'
            $table->string('product_name');
            $table->foreignId('product_id')->nullable()->constrained('watched_products')->nullOnDelete();
            $table->foreignId('track_id')->nullable()->constrained('tracks')->nullOnDelete();
            $table->string('campaign_id')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('google_ads_accounts')->nullOnDelete();
            $table->string('severity', 20)->default('warning'); // 'danger', 'warning', 'info'
            $table->string('reason');
            $table->text('message');
            $table->decimal('spend_cad', 12, 2)->nullable();
            $table->decimal('payout_cad', 12, 2)->nullable();
            $table->decimal('threshold_cad', 12, 2)->nullable();
            $table->unsignedInteger('window_days')->nullable();
            $table->unsignedInteger('conversions_count')->default(0);
            $table->string('action_taken', 32)->default('alert_raised');
            $table->json('details')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'dismissed_at']);
            $table->index(['track_id', 'dismissed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_alerts');
    }
};
