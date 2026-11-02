<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary()->comment('Freelancer.com user ID');
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('public_name')->nullable();
            $table->string('company')->nullable();
            $table->string('country_code', 8)->nullable()->index();
            $table->string('country_name')->nullable();
            $table->string('city')->nullable();
            $table->string('timezone')->nullable();
            $table->string('primary_language', 8)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->boolean('is_payment_verified')->nullable();
            $table->boolean('is_email_verified')->nullable();
            $table->boolean('is_deposit_made')->nullable();
            $table->boolean('is_phone_verified')->nullable();
            $table->boolean('is_identity_verified')->nullable();
            $table->decimal('rating', 4, 2)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }
};
