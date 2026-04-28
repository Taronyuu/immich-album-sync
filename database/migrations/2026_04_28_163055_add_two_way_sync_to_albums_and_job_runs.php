<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('direction')->default('pull')->after('source_type');
            $table->string('source_account_email')->nullable()->after('source_api_key');
            $table->string('source_account_user_id')->nullable()->after('source_account_email');
        });

        Schema::table('job_runs', function (Blueprint $table) {
            $table->unsignedInteger('pushed_count')->default(0)->after('failed_count');
            $table->unsignedInteger('pushed_deduped_count')->default(0)->after('pushed_count');
            $table->unsignedInteger('pushed_failed_count')->default(0)->after('pushed_deduped_count');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn(['direction', 'source_account_email', 'source_account_user_id']);
        });

        Schema::table('job_runs', function (Blueprint $table) {
            $table->dropColumn(['pushed_count', 'pushed_deduped_count', 'pushed_failed_count']);
        });
    }
};
