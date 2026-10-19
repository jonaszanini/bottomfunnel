# Flow Hub

Single-user tracker for one affiliate offer at a time. A Track holds the affiliate link, postback URL, script, Google Ads conversion upload, and IP exclusions. Pages are generated as JSON, rendered in PHP, and exported as one `index.html`.

## Local setup

XAMPP Apache stays on PHP 8.0. This app uses PHP 8.3, installed separately, plus the MariaDB that ships with XAMPP.

```powershell
$php = "C:\Users\jonas\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe"
$env:Path = "$php;" + $env:Path
copy .env.example .env
php artisan key:generate
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS flow_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --seed
npm install
npm run build
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work
```

Sign in with `FLOW_HUB_USERNAME` and `FLOW_HUB_PASSWORD` from `.env`. There is no registration.

Set `OPENAI_API_KEY` before generating a page. Set `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, and `GOOGLE_ADS_DEVELOPER_TOKEN` before linking an account. The OAuth redirect is `GOOGLE_ADS_REDIRECT_URI`. Data Manager conversion upload uses the same Google login and does not use the developer token. Missing credentials stop that call with an error.

`GOOGLE_ADS_API_VERSION` defaults to `v22`.

## What a Track does

1. Create a Track with the product, platform, offer URL, page type, strategy, Google Ads account, and offline conversion action.
2. Generate a page. The selected page type is saved on the page and written onto the Track.
3. Export `index.html`. Host that file and use its URL as the ad destination. The Track script is already in the file.
4. Give the Track postback URL to the affiliate platform. ClickBank uses `tid`, Digistore24 uses `sid`, BuyGoods uses `subid`, and MaxWeb uses `subid1`.
5. A matched postback is stored in full, converted to CAD$ with the Bank of Canada rate, and queued to Data Manager. The same order id updates the existing row. Refunds restate the value.

Strategy is a filter label. Spend is typed in by hand, in CAD$. The dashboard flags three cases and does not pause campaigns: no conversions once spend reaches about 50 percent of the typical commission for one sale, spend at about 70 percent of recorded commission, and ROI at or under 30 percent.

## Hosting

Run the production app in AWS `ca-central-1`.

- One small EC2 instance, or a Laravel Forge server, in that region.
- RDS MySQL in the same region. Do not put the database in another region.
- HTTPS on the app domain so postbacks and the tracker script are served over TLS.
- A queue worker (`php artisan queue:work`) for conversion upload and IP exclusion. The webhook returns after the job is queued.
- Secrets stay in the environment: `APP_KEY`, database password, `OPENAI_API_KEY`, Google client id, client secret, developer token, and `FLOW_HUB_PASSWORD`.

Local development stays on this machine.
