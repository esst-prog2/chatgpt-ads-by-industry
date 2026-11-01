<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered real Ads API account, entered via the admin panel's "Ads
 * Account Management" tab. Live-wired as of 2026-11-01: creating one
 * dispatches SyncAdsAccountJob immediately, and every existing row is
 * re-synced nightly via the ads-accounts:sync-all command - see
 * AdsAccountSyncer and App\Data\DbRegisteredAdsSource, which reads the
 * synced performanceRecords() below to feed the dashboard, the same way
 * RealAdsSource feeds it from .env-configured accounts. internalId() gives
 * the stable "db-account-{id}" string used everywhere else (PerformanceRecord,
 * IndustryMapping, the Rule-of-3 count) that expects a string account id.
 */
#[Fillable(['industry', 'currency', 'ads_api_key'])]
#[Hidden(['ads_api_key', 'ads_api_key_hash'])]
class AdsAccount extends Model
{
    protected function casts(): array
    {
        return [
            'ads_api_key' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // Keeps ads_api_key_hash (a plain SHA-256 digest, not itself
        // encrypted) in sync with ads_api_key on every save, unconditionally
        // - not gated on isDirty('ads_api_key'), so it's correct regardless
        // of how Laravel's dirty-tracking treats an encrypted cast under the
        // hood. This is what the unique index in the
        // add_ads_api_key_hash_to_ads_accounts_table migration actually
        // enforces: the 'encrypted' cast uses a random IV per encryption, so
        // the same plaintext key never produces the same ciphertext twice -
        // a unique constraint directly on ads_api_key would never catch a
        // real duplicate.
        static::saving(function (AdsAccount $account) {
            $account->ads_api_key_hash = hash('sha256', $account->ads_api_key);
        });
    }

    public function performanceRecords(): HasMany
    {
        return $this->hasMany(AdsAccountPerformanceRecord::class);
    }

    public function internalId(): string
    {
        return "db-account-{$this->id}";
    }
}
