<?php

namespace Tests\Unit;

use App\Services\MnbExchangeRateService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises MnbExchangeRateService's real HTTP/XML/caching/fallback behavior
 * directly (not through DashboardService's container-bound ExchangeRateProvider,
 * which tests/TestCase.php rebinds to a network-free fake for every other
 * test) - confirmed against a real live MNB call before writing this
 * (PLANNING_LOG.md 2026-11-01): plain-HTTP endpoint, the SOAP result is
 * itself an XML-escaped string parsed a second time, comma decimal
 * separators, and a Rate unit attribute that isn't always 1.
 */
class MnbExchangeRateServiceTest extends TestCase
{
    private function fakeMnbResponse(string $innerRatesXml, int $status = 200): void
    {
        $inner = '<MNBCurrentExchangeRates><Day date="2026-10-30">'.$innerRatesXml.'</Day></MNBCurrentExchangeRates>';
        $outer = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            .'<GetCurrentExchangeRatesResponse xmlns="http://www.mnb.hu/webservices/">'
            .'<GetCurrentExchangeRatesResult>'.htmlspecialchars($inner, ENT_XML1).'</GetCurrentExchangeRatesResult>'
            .'</GetCurrentExchangeRatesResponse></s:Body></s:Envelope>';

        Http::fake(['http://www.mnb.hu/*' => Http::response($outer, $status, ['Content-Type' => 'text/xml; charset=utf-8'])]);
    }

    public function test_parses_a_real_response_shape_with_comma_decimals_and_a_non_one_unit(): void
    {
        $this->fakeMnbResponse(
            '<Rate unit="1" curr="EUR">365,8</Rate><Rate unit="100" curr="JPY">207,67</Rate>'
        );

        $rates = (new MnbExchangeRateService(['HUF' => 1.0]))->ratesToHuf();

        $this->assertSame(1.0, $rates['HUF']);
        $this->assertEqualsWithDelta(365.8, $rates['EUR'], 0.0001);
        // unit="100" means the quoted rate is per 100 JPY, not per 1.
        $this->assertEqualsWithDelta(2.0767, $rates['JPY'], 0.0001);
    }

    public function test_sends_the_documented_soap_action_and_plain_http_endpoint(): void
    {
        $this->fakeMnbResponse('<Rate unit="1" curr="EUR">400,00</Rate>');

        (new MnbExchangeRateService(['HUF' => 1.0]))->ratesToHuf();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://www.mnb.hu/arfolyamok.asmx'
                && $request->hasHeader('SOAPAction', '"http://www.mnb.hu/webservices/MNBArfolyamServiceSoap/GetCurrentExchangeRates"');
        });
    }

    public function test_falls_back_to_the_static_rate_when_the_live_fetch_fails(): void
    {
        Http::fake(['http://www.mnb.hu/*' => Http::response('', 500)]);

        $rates = (new MnbExchangeRateService(['HUF' => 1.0, 'EUR' => 400.0, 'USD' => 350.0]))->ratesToHuf();

        $this->assertSame(['HUF' => 1.0, 'EUR' => 400.0, 'USD' => 350.0], $rates);
    }

    public function test_falls_back_per_currency_when_the_live_response_is_missing_one(): void
    {
        // Live response only has EUR - USD must still fall back individually,
        // not drag the whole result down to the static fallback.
        $this->fakeMnbResponse('<Rate unit="1" curr="EUR">365,8</Rate>');

        $rates = (new MnbExchangeRateService(['HUF' => 1.0, 'EUR' => 400.0, 'USD' => 350.0]))->ratesToHuf();

        $this->assertEqualsWithDelta(365.8, $rates['EUR'], 0.0001);
        $this->assertSame(350.0, $rates['USD']);
    }

    public function test_caches_a_successful_live_fetch_so_a_second_call_does_not_hit_the_network_again(): void
    {
        $this->fakeMnbResponse('<Rate unit="1" curr="EUR">365,8</Rate>');
        $service = new MnbExchangeRateService(['HUF' => 1.0]);

        $first = $service->ratesToHuf();
        $second = $service->ratesToHuf();

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }
}
