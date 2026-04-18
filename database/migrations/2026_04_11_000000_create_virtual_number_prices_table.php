<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_number_prices', function (Blueprint $table) {
            $table->id();

            // Which service this rule applies to (e.g. 'instagram', 'whatsapp').
            // NULL = applies to ALL services that have no specific rule.
            $table->string('service', 50)->nullable()->index();

            // Which country (2-letter ISO) this rule applies to.
            // NULL = applies to ALL countries that have no specific rule.
            $table->string('country_code', 2)->nullable()->index();

            // If set, use this exact price instead of provider price * markup.
            $table->decimal('fixed_price', 8, 2)->nullable();

            // If set, multiply the raw provider price by this instead of global markup.
            // Ignored when fixed_price is set.
            $table->decimal('markup', 5, 4)->nullable();

            // Human-readable note for the admin UI.
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // A rule is uniquely identified by (service, country_code).
            // Both NULLs = global fallback (but we use VIRTUAL_NUMBER_MARKUP for that).
            $table->unique(['service', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_number_prices');
    }
};
