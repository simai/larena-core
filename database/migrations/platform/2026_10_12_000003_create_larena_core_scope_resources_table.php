<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A resource bound to one scope (a logical file, for example), so access can check it without the resource's owner. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_scope_resources', static function (Blueprint $table): void {
            $table->string('resource_type', 64);
            $table->string('resource_ref', 191);
            $table->string('scope_ref', 80);
            $table->timestamp('created_at')->nullable();

            $table->primary(['resource_type', 'resource_ref'], 'core_scope_resources_pk');
            $table->index(['scope_ref', 'resource_type', 'resource_ref'], 'core_scope_resources_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_scope_resources');
    }
};
