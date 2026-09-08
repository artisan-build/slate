<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_subject_type');
            $table->string('owner_subject_ref');
            $table->string('object_key')->unique();
            $table->string('mode');
            $table->string('status')->index();
            $table->string('rejection_reason')->nullable();
            $table->text('source_url')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('content_type')->nullable();
            $table->string('source_validator_header')->nullable();
            $table->text('source_validator')->nullable();
            $table->unsignedBigInteger('part_size_bytes')->nullable();
            $table->text('multipart_upload_id')->nullable();
            $table->timestamp('retry_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['owner_subject_type', 'owner_subject_ref']);
            $table->index(['mode', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
