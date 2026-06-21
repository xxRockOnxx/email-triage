<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // Encrypted HTML copy for rich frontend rendering (DOMPurify
            // sanitizes it client-side). Mirrors body_enc: contains real PII,
            // so encrypted at rest and never indexed/searched. (Column order is
            // cosmetic on Postgres, so no after() modifier.)
            $table->longText('body_html_enc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn('body_html_enc');
        });
    }
};
