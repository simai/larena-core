<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The older reference a scope is still known by in stored data (`scope:<id>`), kept as an alias until the data is
 * rewritten to the scope's own reference (owner decision 2026-10-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('larena_core_scopes', static function (Blueprint $table): void {
            $table->string('legacy_ref', 128)->nullable()->unique('core_scopes_legacy_ref_uq');
        });
    }

    public function down(): void
    {
        Schema::table('larena_core_scopes', static function (Blueprint $table): void {
            $table->dropUnique('core_scopes_legacy_ref_uq');
            $table->dropColumn('legacy_ref');
        });
    }
};
