# ChatGPT Ads Industry Dashboard

ChatGPT Ads has only recently become available for self-service advertising in Hungary. OpenAI made Ads Manager self-service access available across 31 European markets, including Hungary, on August 31, 2026. Since the advertising platform is still in an early beta stage, it could be interesting to observe how advertising performance develops on the platform and whether performance differs between industries.

## 1. The demo

I open the web application in my browser and select a date range. The application retrieves advertising performance data from multiple ChatGPT Ads accounts and groups the accounts by industry using an external industry classification. The dashboard shows total spend, impressions, clicks, CTR and conversions for each industry, with charts for comparing their performance. I can select an industry to see the accounts and campaigns that contribute to its results.

## 2. The shape

|  |  |
|------------------|------------------------------------------------------|
| in | ChatGPT Ads performance data from multiple advertising accounts + an external mapping of each account to an industry |
| out | web dashboard comparing advertising performance across industries |
| in between | retrieve and aggregate performance data from the Ads API, associate each account with its industry, calculate industry-level metrics, and display the results |

ChatGPT Ads API: <https://developers.openai.com/ads>

## 3. The size

### First useful version

-   Retrieve performance data from multiple ChatGPT Ads accounts.
-   Maintain an account-to-industry mapping using an external source.
-   Aggregate the main advertising metrics by industry.
-   Display spend, impressions, clicks, CTR and conversions.
-   Allow the user to select a date range.
-   Provide charts for comparing industries.

### Not this term

-   Creating, editing or launching advertising campaigns.
-   Automatic campaign optimisation.
-   Predictive analytics or machine-learning-based predictions.

## 4. How we would know it works

-   Given valid Ads API data and an account-to-industry mapping, the dashboard displays the correct aggregated metrics for each industry.
-   Given two accounts belonging to the same industry, their performance data is combined correctly in the industry-level results.

## 5. What could stop this

-   The main technical risk is that I have only basic PHP knowledge and no previous experience with Laravel. Learning and using the framework may make the project significantly harder to implement within the available time.

-   Another risk is access to the required ChatGPT Ads API data and permissions for multiple advertising accounts.

-   The external industry classification may also require some manual work. If real advertising data cannot be shown in class, I will use a small synthetic dataset with the same structure for the demonstration.
