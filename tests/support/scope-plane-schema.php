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
        $table->string('legacy_ref', 128)->nullable()->unique();
        $table->unique(['kind', 'identifier']);
    });

    $schema->create('larena_core_scope_memberships', static function (Blueprint $table): void {
        $table->string('scope_ref', 80);
        $table->string('subject_ref', 191);
        $table->string('status', 16)->default('active');
        $table->string('created_by', 191);
        $table->string('correlation_id', 191)->nullable();
        $table->timestamps();
        $table->primary(['scope_ref', 'subject_ref']);
    });

    $schema->create('larena_core_subject_default_scopes', static function (Blueprint $table): void {
        $table->string('subject_ref', 191)->primary();
        $table->string('scope_ref', 80);
        $table->timestamps();
    });

    $schema->create('larena_core_scope_resources', static function (Blueprint $table): void {
        $table->string('resource_type', 64);
        $table->string('resource_ref', 191);
        $table->string('scope_ref', 80);
        $table->timestamp('created_at')->nullable();
        $table->primary(['resource_type', 'resource_ref']);
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

    $schema->create('larena_core_site_bindings', static function (Blueprint $table): void {
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
        $table->unique(['host', 'folder', 'environment']);
        $table->index(['site_ref', 'position']);
    });

    $schema->create('larena_core_default_site', static function (Blueprint $table): void {
        $table->string('slot', 16)->primary();
        $table->string('site_ref', 80);
        $table->string('updated_by', 191);
        $table->timestamps();
    });

    return $connection;
}

/** An in-memory connection from before the site migrations ran. */
function larena_core_scope_plane_connection_without_sites(): Connection
{
    $connection = larena_core_scope_plane_connection();
    $connection->getSchemaBuilder()->drop('larena_core_site_bindings');
    $connection->getSchemaBuilder()->drop('larena_core_default_site');

    return $connection;
}
