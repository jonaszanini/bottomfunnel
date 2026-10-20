<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watched_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status', 32)->default('watch');
            $table->json('platforms')->nullable();
            $table->decimal('payout_cad', 12, 2)->nullable();
            $table->text('payout_notes')->nullable();
            $table->string('page_type', 64)->nullable();
            $table->string('brand_bidding', 32)->default('unknown');
            $table->string('true_bof', 16)->default('ambiguous');
            $table->json('geos')->nullable();
            $table->text('negatives')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->text('demand_notes')->nullable();
            $table->text('sales_page_url')->nullable();
            $table->text('affiliate_link')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'brand_bidding', 'true_bof']);
            $table->index('name');
            $table->index('last_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watched_products');
    }
};
