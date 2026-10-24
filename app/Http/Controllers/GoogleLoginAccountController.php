<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GoogleLoginAccountStatus;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleLoginAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GoogleLoginAccountController extends Controller
{
    /**
     * Display a listing of Google Ads login accounts.
     */
    public function index(Request $request): Response
    {
        $status = (string) $request->input('status', 'all');
        $search = (string) $request->input('search', '');

        $accounts = GoogleLoginAccount::query()
            ->with([
                'linkedGoogleAdsAccount:id,name,customer_id',
                'campaigns' => fn ($query) => $query->orderBy('campaign_id'),
            ])
            ->withCount('campaigns')
            ->filter(['status' => $status, 'search' => $search])
            ->orderByDesc('id')
            ->get()
            ->map(fn (GoogleLoginAccount $account): array => [
                'id' => $account->id,
                'email' => $account->email,
                'customer_id' => $account->customer_id,
                'formatted_customer_id' => $account->formatted_customer_id,
                'login_customer_id' => $account->login_customer_id,
                'status' => $account->status instanceof GoogleLoginAccountStatus ? $account->status->value : (string) $account->status,
                'status_label' => $account->status instanceof GoogleLoginAccountStatus ? $account->status->label() : ucfirst((string) $account->status),
                'status_badge' => $account->status instanceof GoogleLoginAccountStatus ? $account->status->badgeClass() : 'bg-zinc-500/10 text-zinc-400',
                'currency' => $account->currency,
                'notes' => $account->notes,
                'cancelled_at' => $account->cancelled_at?->toIso8601String(),
                'linked_google_ads_account_id' => $account->linked_google_ads_account_id,
                'linked_google_ads_account' => $account->linkedGoogleAdsAccount ? [
                    'id' => $account->linkedGoogleAdsAccount->id,
                    'name' => $account->linkedGoogleAdsAccount->name,
                    'customer_id' => $account->linkedGoogleAdsAccount->customer_id,
                ] : null,
                'campaigns_count' => $account->campaigns_count,
                'campaigns' => $account->campaigns->map(fn ($c) => [
                    'id' => $c->id,
                    'campaign_id' => $c->campaign_id,
                    'campaign_name' => $c->campaign_name,
                    'status' => $c->status,
                ]),
                'has_password' => $account->has_password,
                'created_at' => $account->created_at?->toIso8601String(),
                'updated_at' => $account->updated_at?->toIso8601String(),
            ]);

        $googleAdsAccounts = GoogleAdsAccount::query()
            ->orderBy('name')
            ->get(['id', 'name', 'customer_id'])
            ->map(fn (GoogleAdsAccount $acc) => [
                'id' => $acc->id,
                'name' => $acc->name,
                'customer_id' => $acc->customer_id,
            ]);

        return Inertia::render('GoogleAccounts/Index', [
            'accounts' => $accounts,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'googleAdsAccounts' => $googleAdsAccounts,
            'statuses' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'suspended', 'label' => 'Suspended'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
        ]);
    }

    /**
     * Store a newly created Google login account.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:1', 'max:500'],
            'customer_id' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $digits = preg_replace('/\D/', '', (string) $value) ?? '';
                    if (strlen($digits) !== 10) {
                        $fail('The Customer ID must contain exactly 10 digits.');
                    }
                },
            ],
            'login_customer_id' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }
                    $digits = preg_replace('/\D/', '', (string) $value) ?? '';
                    if (strlen($digits) !== 10) {
                        $fail('The Login Customer ID must contain exactly 10 digits.');
                    }
                },
            ],
            'status' => ['required', Rule::enum(GoogleLoginAccountStatus::class)],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'linked_google_ads_account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'campaigns' => ['nullable', 'array'],
            'campaigns.*.campaign_id' => ['required_with:campaigns', 'string', 'max:64'],
            'campaigns.*.campaign_name' => ['nullable', 'string', 'max:255'],
            'campaigns.*.status' => ['nullable', 'string', 'in:active,paused,removed'],
        ]);

        $customerId = preg_replace('/\D/', '', (string) $validated['customer_id']) ?? '';
        $loginCustomerId = ! empty($validated['login_customer_id'])
            ? (preg_replace('/\D/', '', (string) $validated['login_customer_id']) ?? null)
            : null;

        $currency = ! empty($validated['currency']) ? strtoupper(trim((string) $validated['currency'])) : null;
        $status = GoogleLoginAccountStatus::from($validated['status']);
        $cancelledAt = $status === GoogleLoginAccountStatus::Cancelled ? now() : null;

        $account = GoogleLoginAccount::create([
            'email' => strtolower(trim((string) $validated['email'])),
            'password' => $validated['password'],
            'customer_id' => $customerId,
            'login_customer_id' => $loginCustomerId,
            'status' => $status,
            'currency' => $currency,
            'notes' => $validated['notes'] ?? null,
            'cancelled_at' => $cancelledAt,
            'linked_google_ads_account_id' => $validated['linked_google_ads_account_id'] ?? null,
        ]);

        $this->syncCampaigns($account, $request->input('campaigns', []));

        return redirect()->route('google-login-accounts.index')
            ->with('success', 'Google login account registered successfully.');
    }

    /**
     * Update the specified Google login account.
     */
    public function update(Request $request, GoogleLoginAccount $googleLoginAccount): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:1', 'max:500'],
            'customer_id' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $digits = preg_replace('/\D/', '', (string) $value) ?? '';
                    if (strlen($digits) !== 10) {
                        $fail('The Customer ID must contain exactly 10 digits.');
                    }
                },
            ],
            'login_customer_id' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }
                    $digits = preg_replace('/\D/', '', (string) $value) ?? '';
                    if (strlen($digits) !== 10) {
                        $fail('The Login Customer ID must contain exactly 10 digits.');
                    }
                },
            ],
            'status' => ['required', Rule::enum(GoogleLoginAccountStatus::class)],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'linked_google_ads_account_id' => ['nullable', 'integer', 'exists:google_ads_accounts,id'],
            'campaigns' => ['nullable', 'array'],
            'campaigns.*.campaign_id' => ['required_with:campaigns', 'string', 'max:64'],
            'campaigns.*.campaign_name' => ['nullable', 'string', 'max:255'],
            'campaigns.*.status' => ['nullable', 'string', 'in:active,paused,removed'],
        ]);

        $customerId = preg_replace('/\D/', '', (string) $validated['customer_id']) ?? '';
        $loginCustomerId = ! empty($validated['login_customer_id'])
            ? (preg_replace('/\D/', '', (string) $validated['login_customer_id']) ?? null)
            : null;

        $currency = ! empty($validated['currency']) ? strtoupper(trim((string) $validated['currency'])) : null;
        $status = GoogleLoginAccountStatus::from($validated['status']);

        // Manage cancelled_at timestamp transitions
        $cancelledAt = $googleLoginAccount->cancelled_at;
        if ($status === GoogleLoginAccountStatus::Cancelled) {
            $cancelledAt = $cancelledAt ?? now();
        } else {
            $cancelledAt = null;
        }

        $dataToUpdate = [
            'email' => strtolower(trim((string) $validated['email'])),
            'customer_id' => $customerId,
            'login_customer_id' => $loginCustomerId,
            'status' => $status,
            'currency' => $currency,
            'notes' => $validated['notes'] ?? null,
            'cancelled_at' => $cancelledAt,
            'linked_google_ads_account_id' => $validated['linked_google_ads_account_id'] ?? null,
        ];

        // Only update password if a new value is submitted
        if (! empty($validated['password'])) {
            $dataToUpdate['password'] = $validated['password'];
        }

        $googleLoginAccount->update($dataToUpdate);

        if ($request->has('campaigns')) {
            $this->syncCampaigns($googleLoginAccount, $request->input('campaigns', []));
        }

        return redirect()->route('google-login-accounts.index')
            ->with('success', 'Google login account updated successfully.');
    }

    /**
     * Remove the specified Google login account from storage.
     */
    public function destroy(GoogleLoginAccount $googleLoginAccount): RedirectResponse
    {
        $email = $googleLoginAccount->email;
        $googleLoginAccount->delete();

        return redirect()->route('google-login-accounts.index')
            ->with('success', "Google login account ({$email}) deleted successfully.");
    }

    /**
     * Reveal decrypted password for authorized users.
     * Rate limited by throttle middleware. Never logs password contents.
     */
    public function revealPassword(Request $request, GoogleLoginAccount $googleLoginAccount): JsonResponse
    {
        $decrypted = $googleLoginAccount->revealPassword();

        return response()->json([
            'ok' => true,
            'password' => $decrypted ?? '',
        ]);
    }

    /**
     * Synchronize linked campaigns for the login account.
     *
     * @param  array<int, array<string, mixed>>  $campaigns
     */
    protected function syncCampaigns(GoogleLoginAccount $account, array $campaigns): void
    {
        $unique = [];
        foreach ($campaigns as $camp) {
            $rawId = trim((string) ($camp['campaign_id'] ?? ''));
            if ($rawId === '') {
                continue;
            }
            $cleanId = preg_replace('/\D/', '', $rawId);
            $cleanId = $cleanId !== '' ? $cleanId : $rawId;

            $status = in_array($camp['status'] ?? 'active', ['active', 'paused', 'removed'], true)
                ? (string) $camp['status']
                : 'active';

            $unique[$cleanId] = [
                'campaign_id' => $cleanId,
                'campaign_name' => ! empty($camp['campaign_name']) ? trim((string) $camp['campaign_name']) : null,
                'status' => $status,
            ];
        }

        // Delete any campaign not in the list
        $account->campaigns()->whereNotIn('campaign_id', array_keys($unique))->delete();

        // Update or insert each campaign
        foreach ($unique as $cid => $attributes) {
            $account->campaigns()->updateOrCreate(
                ['campaign_id' => $cid],
                $attributes
            );
        }
    }
}
