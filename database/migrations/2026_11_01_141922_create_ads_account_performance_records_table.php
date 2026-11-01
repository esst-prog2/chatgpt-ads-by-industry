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
        // Snapshot storage synced by AdsAccountSyncer (dispatched via
        // SyncAdsAccountJob - on creation, and nightly for every existing
        // row via the ads-accounts:sync-all command). The dashboard reads
        // this table through App\Data\DbRegisteredAdsSource instead of
        // calling the live Ads API during a page render - identical in
        // spirit to .env's RealAdsSource, except the live fetch happens in
        // the background, not synchronously per request.
        Schema::create('ads_account_performance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ads_account_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_id');
            $table->string('campaign'); // "Campaign #N" - never the real name, see AdsApiClient
            $table->date('date');
            $table->unsignedBigInteger('impressions');
            $table->unsignedBigInteger('clicks');
            $table->decimal('spend', 14, 2);
            $table->string('currency', 3);
            $table->timestamps();

            $table->index(['ads_account_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ads_account_performance_records');
    }
};
