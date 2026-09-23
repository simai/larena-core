<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_memberships', static function (Blueprint $table): void {
            $table->string('membership_id', 120)->primary();
            $table->string('node_id', 120);
            $table->string('subject_ref', 191);
            $table->string('role_tag', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'subject_ref'], 'core_memberships_node_subject_uq');
            $table->index(['subject_ref', 'status'], 'core_memberships_subject_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_memberships');
    }
};
