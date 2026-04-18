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
        Schema::table('virtual_number_rentals', function (Blueprint $table) {
            $table->string('service_label')->nullable()->after('service');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_number_rentals', function (Blueprint $table) {
            $table->dropColumn('service_label');
        });
    }
};
