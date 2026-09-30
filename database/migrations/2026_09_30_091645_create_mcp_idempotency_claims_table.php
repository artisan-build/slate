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
        Schema::create('mcp_idempotency_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_subject_type');
            $table->string('owner_subject_ref');
            $table->string('tool', 64);
            $table->string('idempotency_key', 200);
            $table->char('intent_hash', 64);
            $table->string('target_type', 32);
            $table->string('target_id');
            $table->string('status', 32)->index();
            $table->uuid('execution_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->unique(
                ['owner_subject_type', 'owner_subject_ref', 'tool', 'idempotency_key'],
                'mcp_idempotency_scope_unique',
            );
            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_idempotency_claims');
    }
};
