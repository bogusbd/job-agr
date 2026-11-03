<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freelancer.com never fills `location.country.code`, so `country_code` was always empty.
     * Store the explicit work location of the project instead.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('country_code');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('location_country')->nullable()->after('is_local');
            $table->string('location_region')->nullable()->after('location_country');
            $table->string('location_city')->nullable()->after('location_region');
            $table->string('location_postal_code', 32)->nullable()->after('location_city');
            $table->decimal('location_latitude', 10, 7)->nullable()->after('location_postal_code');
            $table->decimal('location_longitude', 10, 7)->nullable()->after('location_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn([
                'location_country',
                'location_region',
                'location_city',
                'location_postal_code',
                'location_latitude',
                'location_longitude',
            ]);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('country_code', 8)->nullable();
        });
    }
};
