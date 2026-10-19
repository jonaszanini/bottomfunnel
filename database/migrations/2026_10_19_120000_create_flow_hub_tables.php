<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ads_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id', 20);
            $table->string('login_customer_id', 20)->nullable();
            $table->text('refresh_token');
            $table->string('name');
            $table->string('status')->default('linked');
            $table->timestamps();
            $table->unique('customer_id');
        });

        Schema::create('conversion_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_ads_account_id')->constrained()->cascadeOnDelete();
            $table->string('resource_name');
            $table->string('external_id');
            $table->string('name');
            $table->timestamps();
            $table->unique(['google_ads_account_id', 'external_id']);
        });

        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('product_name');
            $table->string('platform', 32);
            $table->text('affiliate_url');
            $table->string('page_type', 32);
            $table->string('strategy', 32);
            $table->string('language', 12)->nullable();
            $table->decimal('typical_commission_cad', 12, 2)->nullable();
            $table->string('postback_secret', 80)->unique();
            $table->string('public_script_key', 80)->unique();
            $table->foreignId('google_ads_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversion_action_id')->nullable()->constrained()->nullOnDelete();
            $table->json('campaign_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('page_type', 32);
            $table->string('language', 12)->nullable();
            $table->json('structure_json');
            $table->longText('html');
            $table->text('injected_script');
            $table->timestamps();
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 80)->unique();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('page_type', 32);
            $table->string('gclid')->nullable()->index();
            $table->string('gbraid')->nullable();
            $table->string('wbraid')->nullable();
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->text('referrer')->nullable();
            $table->timestamp('landed_at');
            $table->unsignedInteger('time_on_page_seconds')->default(0);
            $table->json('engagement')->nullable();
            $table->unsignedSmallInteger('fraud_score')->default(0);
            $table->text('fraud_reason')->nullable();
            $table->boolean('flagged')->default(false);
            $table->boolean('auto_blocked')->default(false);
            $table->boolean('converted')->default(false);
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamps();
            $table->index(['track_id', 'ip_address']);
            $table->index(['track_id', 'landed_at']);
        });

        Schema::create('conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_id', 191);
            $table->string('gclid')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->decimal('fx_rate', 16, 8)->default(0);
            $table->decimal('commission_value_cad', 12, 2)->default(0);
            $table->string('status', 32);
            $table->string('match_source', 32)->nullable();
            $table->unsignedInteger('upload_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->longText('raw_payload');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->unique(['track_id', 'order_id']);
        });

        Schema::create('ip_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('google_ads_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45);
            $table->text('reason');
            $table->string('campaign_id')->default('');
            $table->string('criterion_resource_name')->nullable();
            $table->string('status', 32);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['track_id', 'ip_address', 'status']);
        });

        Schema::create('ad_spend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('amount_cad', 12, 2);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->char('currency', 3);
            $table->decimal('cad_per_unit', 16, 8);
            $table->timestamps();
            $table->unique(['rate_date', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('ad_spend');
        Schema::dropIfExists('ip_blocks');
        Schema::dropIfExists('conversions');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('conversion_actions');
        Schema::dropIfExists('google_ads_accounts');
    }
};
