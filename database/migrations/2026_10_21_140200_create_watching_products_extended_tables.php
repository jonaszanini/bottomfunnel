<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_score_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('watched_products')->cascadeOnDelete();
            $table->integer('score');
            $table->json('breakdown')->nullable();
            $table->json('flags')->nullable();
            $table->json('inputs_snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
        });

        Schema::create('product_negative_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('watched_products')->cascadeOnDelete();
            $table->string('term', 255);
            $table->string('match_type', 32)->default('broad');
            $table->string('source', 32)->default('base');
            $table->boolean('enabled')->default(true);
            $table->json('strategy_rules')->nullable();
            $table->boolean('manually_overridden')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'term', 'match_type']);
            $table->index(['product_id', 'enabled']);
            $table->index(['product_id', 'source']);
        });

        Schema::create('negative_keyword_seeds', function (Blueprint $table) {
            $table->id();
            $table->string('term', 255)->unique();
            $table->boolean('enabled_by_default')->default(true);
            $table->json('strategy_rules')->nullable();
            $table->timestamps();
        });

        Schema::create('api_usage', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->char('period', 7); // YYYY-MM
            $table->integer('calls')->default(0);
            $table->timestamps();

            $table->unique(['provider', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage');
        Schema::dropIfExists('negative_keyword_seeds');
        Schema::dropIfExists('product_negative_keywords');
        Schema::dropIfExists('product_score_history');
    }
};
