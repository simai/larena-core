<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_scopes', static function (Blueprint $table): void {
            $table->string('scope_ref', 80)->primary();
            $table->string('kind', 32);
            $table->string('identifier', 64);
            $table->string('parent_scope_ref', 80)->nullable();
            $table->string('name', 191);
            $table->string('status', 16)->default('active');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['kind', 'identifier'], 'core_scopes_kind_identifier_uq');
            $table->index('parent_scope_ref', 'core_scopes_parent_idx');
            $table->index('status', 'core_scopes_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_scopes');
    }
};
