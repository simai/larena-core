<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_planes', static function (Blueprint $table): void {
            $table->string('plane_id', 120)->primary();
            $table->string('scope_ref', 80);
            $table->string('plane_key', 64);
            $table->string('kind', 16);
            $table->string('name', 191);
            $table->string('status', 16)->default('active');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['scope_ref', 'plane_key'], 'core_planes_scope_key_uq');
            $table->index(['scope_ref', 'status'], 'core_planes_scope_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_planes');
    }
};
