<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The scope a subject works in when nothing else is asked: one per subject. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_core_subject_default_scopes', static function (Blueprint $table): void {
            $table->string('subject_ref', 191)->primary();
            $table->string('scope_ref', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_core_subject_default_scopes');
    }
};
