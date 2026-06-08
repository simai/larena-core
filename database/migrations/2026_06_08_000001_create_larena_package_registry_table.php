<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_package_registry', static function (Blueprint $table): void {
            $table->id();
            $table->string('package_name')->unique();
            $table->string('package_status')->default('planned');
            $table->string('version')->nullable();
            $table->string('install_path')->nullable();
            $table->string('source')->default('guarded_install_schema_apply');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_package_registry');
    }
};
