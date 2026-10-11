<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A subject's membership in a scope: one person may work in several sites (core scope-model). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_scope_memberships', static function (Blueprint $table): void {
            $table->string('scope_ref', 80);
            $table->string('subject_ref', 191);
            $table->string('status', 16)->default('active');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamps();

            $table->primary(['scope_ref', 'subject_ref'], 'core_scope_memberships_pk');
            $table->index(['subject_ref', 'status'], 'core_scope_memberships_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_scope_memberships');
    }
};
