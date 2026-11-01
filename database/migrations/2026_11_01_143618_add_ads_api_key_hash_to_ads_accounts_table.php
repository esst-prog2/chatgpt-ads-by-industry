<?php

use App\Models\AdsAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A SHA-256 hex digest of the plaintext API key, kept alongside the
     * 'encrypted'-cast ads_api_key column. A unique index can't go directly
     * on ads_api_key: Laravel's encrypted cast uses a random IV per
     * encryption, so the SAME plaintext key produces a DIFFERENT ciphertext
     * every time it's saved - a unique constraint on the ciphertext itself
     * would never actually catch a duplicate. This deterministic hash is
     * the standard pattern for enforcing uniqueness on an encrypted column.
     * Added as nullable first, backfilled for any existing rows, then made
     * unique - adding straight to a column with existing data would fail
     * once two accounts have different valid keys, since this phased
     * add-backfill-constrain order needs no row to be touched twice.
     */
    public function up(): void
    {
        Schema::table('ads_accounts', function (Blueprint $table) {
            $table->string('ads_api_key_hash', 64)->nullable()->after('ads_api_key');
        });

        AdsAccount::all()->each(function (AdsAccount $account) {
            $account->ads_api_key_hash = hash('sha256', $account->ads_api_key);
            $account->saveQuietly();
        });

        Schema::table('ads_accounts', function (Blueprint $table) {
            $table->string('ads_api_key_hash', 64)->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // SQLite refuses to drop a column that a UNIQUE index still
        // references ("no such column ... after drop column") - the same
        // bug already hit and fixed once in this project (PLANNING_LOG.md
        // 2026-10-27): the index has to be dropped first, in its own
        // Schema::table() call, before the column itself.
        Schema::table('ads_accounts', function (Blueprint $table) {
            $table->dropUnique(['ads_api_key_hash']);
        });

        Schema::table('ads_accounts', function (Blueprint $table) {
            $table->dropColumn('ads_api_key_hash');
        });
    }
};
