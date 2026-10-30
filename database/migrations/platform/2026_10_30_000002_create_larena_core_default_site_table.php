<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The site a request gets when no domain matches (decision 22): at most one row, its key fixed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_default_site', static function (Blueprint $table): void {
            $table->string('slot', 16)->primary();
            $table->string('site_ref', 80);
            $table->string('updated_by', 191);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_default_site');
    }
};
