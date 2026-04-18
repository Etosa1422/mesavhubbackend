<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_number_rentals', function (Blueprint $table) {
            $table->id();

            // Owner
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Provider details
            $table->string('provider')->default('twilio');              // sms_activate | five_sim | twilio
            $table->string('provider_rental_id')->unique();             // Provider's activation/rental ID
            $table->string('phone_number');                             // E.164 format, e.g. +19295550182

            // Country snapshot (stored at time of rental so prices never drift)
            $table->string('country_code', 2);         // ISO 3166-1 alpha-2, e.g. US
            $table->string('country_name');
            $table->string('country_flag', 10);
            $table->string('country_dial', 10);        // e.g. +1

            // Which service the user is verifying
            $table->string('service');                 // e.g. instagram

            // Billing
            $table->decimal('price', 8, 2);

            // OTP delivery
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_received_at')->nullable();

            // Lifecycle
            $table->timestamp('expires_at')->useCurrent();
            $table->enum('status', ['active', 'completed', 'expired', 'cancelled'])->default('active');
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('phone_number');    // webhook lookup
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_number_rentals');
    }
};
