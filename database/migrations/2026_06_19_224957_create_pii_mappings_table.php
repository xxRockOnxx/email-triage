<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This table is the key that unlocks de-anonymization. It must never be sent to
        // an LLM and original_value_enc must always be encrypted at rest.
        Schema::create('pii_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();

            // e.g. "PERSON_1", "EMAIL_ADDRESS_2", "PHONE_NUMBER_1" — consistent per-email
            $table->string('placeholder');

            // Presidio entity type, e.g. PERSON, EMAIL_ADDRESS, PHONE_NUMBER, LOCATION, ORGANIZATION
            $table->string('entity_type');

            $table->text('original_value_enc');

            // Presidio's detection confidence for this specific entity (not the triage confidence)
            $table->float('detection_score')->nullable();

            $table->timestamps();

            $table->unique(['email_id', 'placeholder']);
            $table->index('entity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pii_mappings');
    }
};
