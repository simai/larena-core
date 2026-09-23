<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_plane_nodes', static function (Blueprint $table): void {
            $table->string('node_id', 120)->primary();
            $table->string('plane_id', 120);
            $table->string('parent_node_id', 120)->nullable();
            $table->string('node_key', 64);
            $table->string('path', 512);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedInteger('order_index')->default(0);
            $table->string('name', 191);
            $table->string('status', 16)->default('active');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamps();

            // Explicit short names: the generated names exceed the MySQL
            // 64-character identifier limit.
            $table->unique(['plane_id', 'node_key'], 'core_plane_nodes_key_uq');
            $table->index(['plane_id', 'path'], 'core_plane_nodes_path_idx');
            $table->index(['plane_id', 'parent_node_id', 'order_index'], 'core_plane_nodes_parent_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_plane_nodes');
    }
};
