<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * Creates the Batch 1 core scope and plane schema on an in-memory connection.
 * The shapes mirror the frozen migrations one to one.
 */
function larena_core_scope_plane_connection(): Connection
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    $schema = $connection->getSchemaBuilder();

    $schema->create('larena_core_scopes', static function (Blueprint $table): void {
        $table->string('scope_ref', 80)->primary();
        $table->string('kind', 32);
        $table->string('identifier', 64);
        $table->string('parent_scope_ref', 80)->nullable();
        $table->string('name', 191);
        $table->string('status', 16)->default('active');
        $table->string('created_by', 191);
        $table->string('correlation_id', 191)->nullable();
        $table->timestamps();
        $table->unique(['kind', 'identifier']);
    });

    $schema->create('larena_core_planes', static function (Blueprint $table): void {
        $table->string('plane_id', 120)->primary();
        $table->string('scope_ref', 80);
        $table->string('plane_key', 64);
        $table->string('kind', 16);
        $table->string('name', 191);
        $table->string('status', 16)->default('active');
        $table->string('created_by', 191);
        $table->string('correlation_id', 191)->nullable();
        $table->timestamps();
        $table->unique(['scope_ref', 'plane_key']);
    });

    $schema->create('larena_core_plane_nodes', static function (Blueprint $table): void {
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
        $table->unique(['plane_id', 'node_key']);
    });

    $schema->create('larena_core_memberships', static function (Blueprint $table): void {
        $table->string('membership_id', 120)->primary();
        $table->string('node_id', 120);
        $table->string('subject_ref', 191);
        $table->string('role_tag', 64)->nullable();
        $table->string('status', 16)->default('active');
        $table->string('created_by', 191);
        $table->string('correlation_id', 191)->nullable();
        $table->timestamps();
        $table->unique(['node_id', 'subject_ref']);
    });

    return $connection;
}
