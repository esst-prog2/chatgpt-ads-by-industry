# ChatGPT Ads Industry Dashboard

ChatGPT Ads has only recently become available for self-service advertising in Hungary. OpenAI made Ads Manager self-service access available across 31 European markets, including Hungary, on August 31, 2026. Since the advertising platform is still in an early beta stage, it could be interesting to observe how advertising performance develops on the platform and whether performance differs between industries.

## 1. The demo

I open the web application in my browser. The date range defaults to the last 28 days and I can change it. The application groups accounts by industry using a local mapping file that uses the official industry list. The dashboard shows a box plot comparing industries, where each point is one campaign, and I can switch the metric between impressions, clicks, spend and CTR. I can select an industry to see the accounts and campaigns that contribute to its results, with each account marked as Real or Synthetic. The data comes from one real ChatGPT Ads account (mapped to Education & Careers) plus synthetic accounts.

## 2. The shape

|  |  |
|------------------|------------------------------------------------------|
| in | ChatGPT Ads performance data from multiple advertising accounts + an external mapping of each account to an industry |
| out | web dashboard comparing advertising performance across industries |
| in between | retrieve and aggregate performance data from the Ads API, associate each account with its industry, calculate industry-level metrics, and display the results |

ChatGPT Ads API: <https://developers.openai.com/ads>

## 3. The size

### First useful version

-   Built with Laravel.
-   Read performance data from exactly one real ChatGPT Ads account (one API key), plus a synthetic dataset for the other accounts (3 industries, 2-3 accounts each, 2-4 campaigns per account, 30 days of daily data). Education & Careers holds the real account and 1-2 synthetic accounts.
-   Maintain an account-to-industry mapping in a local CSV or JSON file, using the official industry list.
-   Aggregate impressions, clicks, spend and CTR (clicks / impressions from summed totals) by industry.
-   Allow the user to select a date range, defaulting to the last 28 days.
-   Show a box plot comparing industries (one point per campaign) with a selector to switch the metric.
-   Drill down from an industry to its accounts and campaigns, with each account labelled Real or Synthetic.

### Not this term

-   Conversions.
-   More than one real API key, or partner access.
-   Creating, editing or launching advertising campaigns.
-   Automatic campaign optimisation.
-   Predictive analytics or machine-learning-based predictions.

## 4. How we would know it works

-   Given the synthetic dataset and an account-to-industry mapping, the dashboard displays the correct aggregated metrics for each industry.
-   Given two accounts belonging to the same industry (including Education & Careers, which has the real account and synthetic ones), their performance data is combined correctly in the industry-level results.

## 5. What could stop this

-   The main technical risk is that I have only basic PHP knowledge and no previous experience with Laravel. Learning and using the framework may make the project significantly harder to implement within the available time.

-   Another risk is access to the ChatGPT Ads API. Each API key covers a single ad account, so the MVP uses exactly one real key and synthetic data for all other accounts. If the real account cannot be shown in class or has little data, the dashboard still works with the synthetic accounts.

-   The account-to-industry mapping is a small local file that needs some manual work.

## 6. Running the MVP

1.  Install PHP and Composer (for example with Laravel Herd), then run `composer install` and `cp .env.example .env && php artisan key:generate`.
2.  Optional: put your one ChatGPT Ads API key in `.env` as `ADS_API_KEY`. Without it, the dashboard shows the synthetic accounts and a notice.
3.  `php artisan serve`, then open <http://127.0.0.1:8000>.
4.  `php artisan test` runs the tests.

---

Planned and specified with [OpenSpec](https://openspec.dev); the change proposal, specs, design and tasks live under `openspec/changes/add-industry-dashboard-mvp/`. Every project decision is logged in [PLANNING_LOG.md](PLANNING_LOG.md).

