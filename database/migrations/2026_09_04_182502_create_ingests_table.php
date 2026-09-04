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
        Schema::create('ingests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained()->cascadeOnDelete();
            $table->string('object_key')->unique();
            $table->string('mode');
            $table->string('status')->default('expected')->index();
            $table->text('source_url')->nullable();
            $table->unsignedBigInteger('expected_bytes')->nullable();
            $table->string('source_validator_header')->nullable();
            $table->text('source_validator')->nullable();
            $table->unsignedBigInteger('part_size_bytes')->nullable();
            $table->text('multipart_upload_id')->nullable();
            $table->timestamp('retry_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['mode', 'status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingests');
    }
};
