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
        Schema::create('ads_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('industry'); // validated against App\Data\Industries::ALL in the controller
            $table->string('currency', 3); // USD | EUR | HUF
            // text, not string: Laravel's 'encrypted' cast (AES-256-CBC, base64
            // JSON envelope) produces ciphertext several times longer than the
            // plaintext key - varchar risks silent truncation.
            $table->text('ads_api_key');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ads_accounts');
    }
};
