<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary()->comment('Freelancer.com project ID');
            $table->unsignedBigInteger('client_id')->nullable()->index()->comment('Freelancer.com owner_id');
            $table->string('title');
            $table->string('seo_url')->nullable();
            $table->text('preview_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('type', 16)->index();
            $table->string('status', 32)->nullable();
            $table->string('frontend_status', 32)->nullable();
            $table->string('language', 8)->nullable()->index();

            $table->string('currency_code', 8)->nullable()->index();
            $table->string('currency_sign', 8)->nullable();
            $table->decimal('exchange_rate', 16, 8)->nullable();
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->decimal('budget_min_usd', 14, 2)->nullable()->index();
            $table->decimal('budget_max_usd', 14, 2)->nullable()->index();
            $table->unsignedSmallInteger('hourly_hours')->nullable();
            $table->string('hourly_interval', 16)->nullable();

            $table->unsignedInteger('bid_count')->default(0)->index();
            $table->decimal('bid_avg', 14, 2)->nullable();
            $table->decimal('bid_avg_usd', 14, 2)->nullable();
            $table->unsignedSmallInteger('bid_period')->nullable();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_urgent')->default(false);
            $table->boolean('is_sealed')->default(false);
            $table->boolean('is_nda')->default(false);
            $table->boolean('is_fulltime')->default(false);
            $table->boolean('is_local')->default(false);
            $table->boolean('is_hireme')->default(false);
            $table->string('country_code', 8)->nullable();

            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('remote_updated_at')->nullable()->index();
            $table->json('raw')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable()->index();

            $table->boolean('is_viewed')->default(false)->index();
            $table->timestamp('viewed_at')->nullable();
            $table->boolean('is_garbage')->default(false)->index();

            $table->timestamps();
        });
    }
};
