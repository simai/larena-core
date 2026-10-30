<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domains of a site (sites plan, stage 2.1): a host (`*.` allowed), an optional folder, a language, a role and an
 * environment, in the site's own order. A host and folder belong to one site per environment; no folder is stored as
 * an empty string so the unique index covers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_site_bindings', static function (Blueprint $table): void {
            $table->id();
            $table->string('site_ref', 80);
            $table->unsignedInteger('position');
            $table->string('host', 253);
            $table->string('folder', 191)->default('');
            $table->string('locale', 16)->nullable();
            $table->string('role', 16);
            $table->string('environment', 16);
            $table->string('created_by', 191);
            $table->string('updated_by', 191);
            $table->timestamps();

            $table->unique(['host', 'folder', 'environment'], 'core_site_bindings_address_uq');
            $table->index(['site_ref', 'position'], 'core_site_bindings_site_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_site_bindings');
    }
};
