<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_install_state', static function (Blueprint $table): void {
            $table->id();
            $table->string('state_key')->unique();
            $table->string('state_status')->default('planned');
            $table->string('launch_record_id')->nullable();
            $table->string('evidence_path')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_install_state');
    }
};
