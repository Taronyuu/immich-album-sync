<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('schedule');
            $table->string('source_type');
            $table->string('source_base_url');
            $table->text('source_share_key')->nullable();
            $table->text('source_share_password')->nullable();
            $table->text('source_api_key')->nullable();
            $table->string('source_album_id')->nullable();
            $table->string('target_album_name');
            $table->string('target_album_id')->nullable();
            $table->string('on_remote_delete')->default('remove-from-album');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_jobs');
    }
};
