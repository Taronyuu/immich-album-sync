<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mappings', function (Blueprint $table) {
            $table->foreignId('sync_job_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id');
            $table->string('remote_checksum')->nullable();
            $table->string('local_asset_id');
            $table->timestamp('imported_at');
            $table->primary(['sync_job_id', 'remote_id']);
            $table->index('local_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mappings');
    }
};
