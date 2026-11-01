<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Fully automatic daily HUF exchange rate, fetched live from MNB (Magyar
 * Nemzeti Bank)'s public SOAP service - replaces the static EUR_TO_HUF_RATE
 * as the primary source (PLANNING_LOG.md 2026-11-01). The endpoint, SOAPAction
 * format and the double-encoded response shape below were all confirmed
 * against a real live call before writing this, not guessed: the SOAP
 * address must be plain HTTP (the HTTPS vhost 404s for this operation), the
 * <GetCurrentExchangeRatesResult> is itself an XML-escaped string that has to
 * be parsed a second time, its <Rate> values use a COMMA decimal separator,
 * and the <Rate unit="..."> attribute is not always 1 (e.g. JPY is per 100).
 */
final class MnbExchangeRateService implements ExchangeRateProvider
{
    private const ENDPOINT = 'http://www.mnb.hu/arfolyamok.asmx';

    private const SOAP_ACTION = 'http://www.mnb.hu/webservices/MNBArfolyamServiceSoap/GetCurrentExchangeRates';

    private const CACHE_KEY = 'mnb_exchange_rates_to_huf';

    // MNB publishes once per business day, so a day-long cache is already
    // generous; a weekend/holiday request just keeps returning the same
    // last-published rate, which is correct (confirmed live: a Sunday
    // request returned the preceding Friday's rate).
    private const SUCCESS_TTL_SECONDS = 86400;

    // On failure, retry sooner than a full day rather than being stuck on
    // the static fallback until the TTL above expires - but still back off
    // so a down endpoint doesn't add a live HTTP call (with its own timeout)
    // to every single dashboard request during an outage.
    private const FAILURE_RETRY_TTL_SECONDS = 300;

    /**
     * @param  array<string, float>  $fallbackRates  currency => HUF rate, used
     *      per-currency whenever the live fetch doesn't provide that currency
     *      (now or ever) or fails entirely - e.g. config('ads.exchange_rates_to_huf').
     */
    public function __construct(private readonly array $fallbackRates) {}

    /** @return array<string, float> currency => HUF rate */
    public function ratesToHuf(): array
    {
        $live = Cache::get(self::CACHE_KEY);

        if ($live === null) {
            try {
                $live = $this->fetchLive();
                Cache::put(self::CACHE_KEY, $live, self::SUCCESS_TTL_SECONDS);
            } catch (Throwable $e) {
                Log::channel(config('logging.default'))->warning('mnb.exchange_rate_fetch_failed', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                // Cache the empty result too (not null), so the next request
                // within the backoff window skips straight to the fallback
                // below instead of attempting another live call.
                $live = [];
                Cache::put(self::CACHE_KEY, $live, self::FAILURE_RETRY_TTL_SECONDS);
            }
        }

        // Fallback is applied per-currency, so a live response that's missing
        // one currency (or failed entirely, $live === []) doesn't lose every
        // other currency's live-ness - only the missing ones fall back.
        return array_merge($this->fallbackRates, $live);
    }

    /** @return array<string, float> */
    private function fetchLive(): array
    {
        $envelope = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <GetCurrentExchangeRates xmlns="http://www.mnb.hu/webservices/"/>
          </soap:Body>
        </soap:Envelope>
        XML;

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"'.self::SOAP_ACTION.'"',
        ])->timeout(10)->send('POST', self::ENDPOINT, ['body' => $envelope]);

        if ($response->failed()) {
            throw new RuntimeException('MNB SOAP endpoint returned HTTP '.$response->status());
        }

        $outer = new SimpleXMLElement($response->body());
        $outer->registerXPathNamespace('mnb', 'http://www.mnb.hu/webservices/');
        $resultNodes = $outer->xpath('//mnb:GetCurrentExchangeRatesResult');

        if (empty($resultNodes)) {
            throw new RuntimeException('MNB SOAP response did not contain GetCurrentExchangeRatesResult');
        }

        // The result is itself an XML document encoded as a string value -
        // confirmed live, not assumed - so it has to be parsed a second time.
        $inner = new SimpleXMLElement((string) $resultNodes[0]);

        $rates = ['HUF' => 1.0];
        foreach ($inner->Day->Rate as $rate) {
            $unit = (float) $rate['unit'];
            if ($unit <= 0) {
                continue;
            }
            // MNB uses a comma decimal separator (e.g. "365,8"), confirmed live.
            $value = (float) str_replace(',', '.', (string) $rate);
            $rates[(string) $rate['curr']] = $value / $unit;
        }

        return $rates;
    }
}
