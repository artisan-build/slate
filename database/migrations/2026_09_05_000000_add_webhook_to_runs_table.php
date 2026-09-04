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
        Schema::table('runs', function (Blueprint $table) {
            $table->string('owner_subject_type')->after('recipe');
            $table->string('owner_subject_ref')->after('owner_subject_type');
            $table->index(['owner_subject_type', 'owner_subject_ref']);
            $table->text('webhook_url')->nullable()->after('rejection_reason');
            $table->text('webhook_secret')->nullable()->after('webhook_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropIndex(['owner_subject_type', 'owner_subject_ref']);
            $table->dropColumn([
                'owner_subject_type',
                'owner_subject_ref',
                'webhook_url',
                'webhook_secret',
            ]);
        });
    }
};
