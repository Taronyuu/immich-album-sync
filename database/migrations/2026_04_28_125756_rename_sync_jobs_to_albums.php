<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('sync_jobs', 'albums');

        Schema::table('mappings', function (Blueprint $table) {
            $table->renameColumn('sync_job_id', 'album_id');
        });
    }

    public function down(): void
    {
        Schema::table('mappings', function (Blueprint $table) {
            $table->renameColumn('album_id', 'sync_job_id');
        });

        Schema::rename('albums', 'sync_jobs');
    }
};
