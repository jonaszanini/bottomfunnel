<?php

namespace App\Services\Google;

use App\Models\GoogleAdsAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleAdsClient
{
    public function authorizationUrl(string $state): string
    {
        $this->assertOauthConfigured();

        $query = http_build_query([
            'client_id' => config('flowhub.google_ads.client_id'),
            'redirect_uri' => config('flowhub.google_ads.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/adwords',
                'https://www.googleapis.com/auth/datamanager',
            ]),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $this->assertOauthConfigured();
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('flowhub.google_ads.client_id'),
            'client_secret' => config('flowhub.google_ads.client_secret'),
            'redirect_uri' => config('flowhub.google_ads.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        return $this->tokenPayload($response, true);
    }

    public function accessToken(GoogleAdsAccount $account): string
    {
        $cacheKey = 'google-ads-token-'.$account->id;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->refresh($account->refresh_token);
        Cache::put($cacheKey, $token['access_token'], max(60, $token['expires_in'] - 60));

        return $token['access_token'];
    }

    /**
     * @return list<string>
     */
    public function listAccessibleCustomers(string $accessToken): array
    {
        $this->assertDeveloperToken();
        $response = $this->send($accessToken, null, 'GET', '/customers:listAccessibleCustomers');
        $names = $response['resourceNames'] ?? [];

        return array_values(array_map(
            fn (string $name): string => $this->digits(str_replace('customers/', '', $name)),
            array_filter($names, 'is_string'),
        ));
    }

    public function customerName(string $accessToken, string $customerId, ?string $loginCustomerId): string
    {
        $customerId = $this->digits($customerId);
        $result = $this->search($accessToken, $customerId, $loginCustomerId, 'SELECT customer.descriptive_name FROM customer LIMIT 1');
        $name = $result[0]['customer']['descriptiveName'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'Google Ads '.$customerId;
    }

    /**
     * @return list<array{id: string, name: string, resource_name: string}>
     */
    public function listConversionActions(GoogleAdsAccount $account): array
    {
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT conversion_action.id, conversion_action.name, conversion_action.resource_name, conversion_action.type, conversion_action.status FROM conversion_action WHERE conversion_action.status = 'ENABLED'",
        );

        $actions = [];
        foreach ($rows as $row) {
            $action = $row['conversionAction'] ?? null;
            if (! is_array($action) || ($action['type'] ?? '') !== 'UPLOAD_CLICKS') {
                continue;
            }
            $actions[] = [
                'id' => (string) ($action['id'] ?? ''),
                'name' => (string) ($action['name'] ?? 'Conversion'),
                'resource_name' => (string) ($action['resourceName'] ?? ''),
            ];
        }

        return $actions;
    }

    /**
     * @return array{id: string, name: string, resource_name: string}
     */
    public function createConversionAction(GoogleAdsAccount $account, string $name): array
    {
        $customerId = $this->digits($account->customer_id);
        $payload = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/conversionActions:mutate',
            [
                'operations' => [[
                    'create' => [
                        'name' => $name,
                        'type' => 'UPLOAD_CLICKS',
                        'category' => 'PURCHASE',
                        'status' => 'ENABLED',
                        'countingType' => 'ONE_PER_CLICK',
                    ],
                ]],
            ],
        );

        $resource = (string) ($payload['results'][0]['resourceName'] ?? '');
        if ($resource === '') {
            throw new GoogleRequestException('Google Ads did not return the new conversion action.');
        }

        $id = basename($resource);

        return [
            'id' => $id,
            'name' => $name,
            'resource_name' => $resource,
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function listCampaigns(GoogleAdsAccount $account): array
    {
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT campaign.id, campaign.name, campaign.status FROM campaign WHERE campaign.status != 'REMOVED' ORDER BY campaign.name",
        );

        $campaigns = [];
        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? null;
            if (! is_array($campaign)) {
                continue;
            }
            $campaigns[] = [
                'id' => (string) ($campaign['id'] ?? ''),
                'name' => (string) ($campaign['name'] ?? 'Campaign'),
            ];
        }

        return $campaigns;
    }

    public function blockIp(GoogleAdsAccount $account, string $campaignId, string $ip): string
    {
        $customerId = $this->digits($account->customer_id);
        $campaignId = $this->digits($campaignId);
        $existing = $this->findIpCriterion($account, $campaignId, $ip);
        if ($existing !== null) {
            return $existing;
        }

        $payload = $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaignCriteria:mutate',
            [
                'operations' => [[
                    'create' => [
                        'campaign' => 'customers/'.$customerId.'/campaigns/'.$campaignId,
                        'negative' => true,
                        'ipBlock' => ['ipAddress' => $ip],
                    ],
                ]],
            ],
        );

        $resource = (string) ($payload['results'][0]['resourceName'] ?? '');
        if ($resource === '') {
            throw new GoogleRequestException('Google Ads did not return the IP exclusion.');
        }

        return $resource;
    }

    public function removeCriterion(GoogleAdsAccount $account, string $resourceName): void
    {
        $customerId = $this->digits($account->customer_id);
        $this->mutate(
            $this->accessToken($account),
            $customerId,
            $account->login_customer_id,
            '/campaignCriteria:mutate',
            [
                'operations' => [[
                    'remove' => $resourceName,
                ]],
            ],
        );
    }

    private function findIpCriterion(GoogleAdsAccount $account, string $campaignId, string $ip): ?string
    {
        $rows = $this->search(
            $this->accessToken($account),
            $account->customer_id,
            $account->login_customer_id,
            "SELECT campaign_criterion.resource_name, campaign_criterion.ip_block.ip_address FROM campaign_criterion WHERE campaign.id = {$campaignId} AND campaign_criterion.type = 'IP_BLOCK'",
        );

        foreach ($rows as $row) {
            $criterion = $row['campaignCriterion'] ?? null;
            if (! is_array($criterion)) {
                continue;
            }
            $blocked = $criterion['ipBlock']['ipAddress'] ?? '';
            if ($blocked === $ip) {
                return (string) ($criterion['resourceName'] ?? '');
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $accessToken, string $customerId, ?string $loginCustomerId, string $query): array
    {
        $customerId = $this->digits($customerId);
        $payload = $this->send($accessToken, $loginCustomerId, 'POST', '/customers/'.$customerId.'/googleAds:search', [
            'query' => $query,
        ]);

        return is_array($payload['results'] ?? null) ? $payload['results'] : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function mutate(string $accessToken, string $customerId, ?string $loginCustomerId, string $path, array $body): array
    {
        return $this->send($accessToken, $loginCustomerId, 'POST', '/customers/'.$customerId.$path, $body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $accessToken, ?string $loginCustomerId, string $method, string $path, ?array $body = null): array
    {
        $this->assertDeveloperToken();
        $version = (string) config('flowhub.google_ads.version', 'v22');
        $headers = [
            'Authorization' => 'Bearer '.$accessToken,
            'developer-token' => (string) config('flowhub.google_ads.developer_token'),
        ];
        $login = $this->digits((string) $loginCustomerId);
        if ($login !== '') {
            $headers['login-customer-id'] = $login;
        }

        $request = Http::withHeaders($headers)->timeout(25)->acceptJson();
        $url = 'https://googleads.googleapis.com/'.$version.$path;
        $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body ?? []);

        return $this->jsonOrFail($response);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function refresh(string $refreshToken): array
    {
        $this->assertOauthConfigured();
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('flowhub.google_ads.client_id'),
            'client_secret' => config('flowhub.google_ads.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        return $this->tokenPayload($response, false);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function tokenPayload(Response $response, bool $requireRefresh): array
    {
        if ($response->failed()) {
            $message = $response->json('error_description') ?: $response->json('error') ?: 'Google did not return a token.';
            throw new GoogleRequestException('Google OAuth failed: '.$message, $response->status() >= 500);
        }

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        if (! is_string($access) || $access === '') {
            throw new GoogleRequestException('Google OAuth did not return an access token.');
        }
        if ($requireRefresh && (! is_string($refresh) || $refresh === '')) {
            throw new GoogleRequestException('Google did not return a refresh token. Remove the app access in the Google Account and link it again.');
        }

        return [
            'access_token' => $access,
            'refresh_token' => is_string($refresh) ? $refresh : '',
            'expires_in' => (int) ($response->json('expires_in') ?: 3600),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOrFail(Response $response): array
    {
        if ($response->failed()) {
            $message = $this->errorMessage($response);
            $transient = in_array($response->status(), [429, 500, 502, 503, 504], true);
            throw new GoogleRequestException($message, $transient);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function errorMessage(Response $response): string
    {
        $errors = $response->json('error.details.0.errors') ?? $response->json('error.errors');
        if (is_array($errors) && isset($errors[0]['message'])) {
            return 'Google Ads: '.$errors[0]['message'];
        }
        $message = $response->json('error.message');
        if (is_string($message) && $message !== '') {
            return 'Google Ads: '.$message;
        }

        return 'Google Ads request failed with status '.$response->status().'.';
    }

    public function assertOauthConfigured(): void
    {
        if (! config('flowhub.google_ads.client_id') || ! config('flowhub.google_ads.client_secret')) {
            throw new GoogleRequestException('Google OAuth is not configured. Set GOOGLE_ADS_CLIENT_ID and GOOGLE_ADS_CLIENT_SECRET.');
        }
    }

    private function assertDeveloperToken(): void
    {
        $this->assertOauthConfigured();
        if (! config('flowhub.google_ads.developer_token')) {
            throw new GoogleRequestException('Google Ads is not configured. Set GOOGLE_ADS_DEVELOPER_TOKEN. Conversion upload through Data Manager does not use this token, but account linking, conversion actions, and IP exclusions do.');
        }
    }

    public function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
