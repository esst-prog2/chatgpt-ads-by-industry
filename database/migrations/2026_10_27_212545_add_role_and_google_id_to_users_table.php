<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 'admin' | 'viewer' - see App\Enums\UserRole. Defaults to viewer
            // so any row created without an explicit role (e.g. the dev
            // seeder) is least-privilege by default.
            $table->string('role')->default('viewer');
            // Nullable/unique: not required for login (email is the match
            // key - see PLANNING_LOG.md 2026-10-27), but robust against a
            // user later changing their Google account's email.
            $table->string('google_id')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // SQLite refuses to drop a column that a UNIQUE index still
        // references ("error in index ... after drop column: no such
        // column") - confirmed directly against a copy of the dev DB, not
        // assumed. The index has to go first, then the column; role has no
        // index so it can drop in the same pass.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'google_id']);
        });
    }
};
