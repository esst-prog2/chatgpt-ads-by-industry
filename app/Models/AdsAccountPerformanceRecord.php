<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One campaign-day snapshot for a database-registered AdsAccount, synced by
 * AdsAccountSyncer. Read by App\Data\DbRegisteredAdsSource to feed the
 * dashboard - never fetched live during a page render.
 */
#[Fillable(['ads_account_id', 'campaign_id', 'campaign', 'date', 'impressions', 'clicks', 'spend', 'currency'])]
class AdsAccountPerformanceRecord extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'spend' => 'float',
        ];
    }

    public function adsAccount(): BelongsTo
    {
        return $this->belongsTo(AdsAccount::class);
    }
}
