<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per request to MAM (scheduled, manual or after saving a mam_id).
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 16);
            $table->boolean('reached');
            $table->boolean('success')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('message')->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('asn')->nullable();
            $table->string('as_org')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
