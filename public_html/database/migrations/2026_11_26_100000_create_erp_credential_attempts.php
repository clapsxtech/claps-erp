<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Named like the other ERP migrations: some tests load them by class name.
class CreateErpCredentialAttempts extends Migration
{
    public function up(): void
    {
        // Failed public sign-in counters (teacher date-of-birth forms), kept out of the
        // container's disk cache so lockouts survive restarts. Keys are SHA-256 digests.
        Schema::create('erp_credential_attempts', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->unsignedInteger('attempts');
            $table->unsignedBigInteger('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_credential_attempts');
    }
}
