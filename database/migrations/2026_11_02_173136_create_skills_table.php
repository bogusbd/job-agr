<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary()->comment('Freelancer.com job ID');
            $table->string('name')->index();
            $table->string('seo_url')->nullable();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('category_name')->nullable();
            $table->timestamps();
        });
    }
};
