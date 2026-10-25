<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AffiliateNetwork;
use App\Enums\AffiliateNetworkAccountStatus;
use App\Models\AffiliateNetworkAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateNetworkAccountController extends Controller
{
    /**
     * Display a listing of affiliate network accounts.
     */
    public function index(Request $request): Response
    {
        $status = (string) $request->input('status', 'all');
        $network = (string) $request->input('network', 'all');
        $search = (string) $request->input('search', '');

        $accounts = AffiliateNetworkAccount::query()
            ->filter([
                'status' => $status,
                'network' => $network,
                'search' => $search,
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (AffiliateNetworkAccount $acc): array => [
                'id' => $acc->id,
                'network' => $acc->network instanceof AffiliateNetwork ? $acc->network->value : (string) $acc->network,
                'network_label' => $acc->network instanceof AffiliateNetwork ? $acc->network->label() : ucfirst((string) $acc->network),
                'network_badge' => $acc->network instanceof AffiliateNetwork ? $acc->network->badgeClass() : 'bg-zinc-500/10 text-zinc-500 border-zinc-500/20',
                'network_custom' => $acc->network_custom,
                'display_network_name' => $acc->display_network_name,
                'nickname' => $acc->nickname,
                'email' => $acc->email,
                'username' => $acc->username,
                'account_id' => $acc->account_id,
                'status' => $acc->status instanceof AffiliateNetworkAccountStatus ? $acc->status->value : (string) $acc->status,
                'status_label' => $acc->status instanceof AffiliateNetworkAccountStatus ? $acc->status->label() : ucfirst((string) $acc->status),
                'status_badge' => $acc->status instanceof AffiliateNetworkAccountStatus ? $acc->status->badgeClass() : 'bg-zinc-500/10 text-zinc-500 border-zinc-500/20',
                'login_url' => $acc->login_url,
                'notes' => $acc->notes,
                'default_currency' => $acc->default_currency,
                'payment_method_hint' => $acc->payment_method_hint,
                'has_password' => $acc->has_password,
                'created_at' => $acc->created_at?->toIso8601String(),
                'updated_at' => $acc->updated_at?->toIso8601String(),
            ]);

        $networks = array_map(fn (AffiliateNetwork $net) => [
            'value' => $net->value,
            'label' => $net->label(),
        ], AffiliateNetwork::cases());

        $statuses = array_map(fn (AffiliateNetworkAccountStatus $st) => [
            'value' => $st->value,
            'label' => $st->label(),
        ], AffiliateNetworkAccountStatus::cases());

        return Inertia::render('AffiliateAccounts/Index', [
            'accounts' => $accounts,
            'filters' => [
                'status' => $status,
                'network' => $network,
                'search' => $search,
            ],
            'networks' => $networks,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Store a newly created affiliate network account.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'network' => ['required', Rule::enum(AffiliateNetwork::class)],
            'network_custom' => ['nullable', 'string', 'max:100', 'required_if:network,other'],
            'nickname' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:1', 'max:500'],
            'account_id' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::enum(AffiliateNetworkAccountStatus::class)],
            'login_url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'payment_method_hint' => ['nullable', 'string', 'max:255'],
        ]);

        $network = AffiliateNetwork::from($validated['network']);
        $networkCustom = $network === AffiliateNetwork::Other
            ? trim((string) ($validated['network_custom'] ?? ''))
            : null;

        $currency = ! empty($validated['default_currency'])
            ? strtoupper(trim((string) $validated['default_currency']))
            : null;

        AffiliateNetworkAccount::create([
            'network' => $network,
            'network_custom' => $networkCustom ?: null,
            'nickname' => trim((string) $validated['nickname']),
            'email' => ! empty($validated['email']) ? strtolower(trim((string) $validated['email'])) : null,
            'username' => ! empty($validated['username']) ? trim((string) $validated['username']) : null,
            'password' => $validated['password'],
            'account_id' => ! empty($validated['account_id']) ? trim((string) $validated['account_id']) : null,
            'status' => AffiliateNetworkAccountStatus::from($validated['status']),
            'login_url' => ! empty($validated['login_url']) ? trim((string) $validated['login_url']) : null,
            'notes' => $validated['notes'] ?? null,
            'default_currency' => $currency,
            'payment_method_hint' => ! empty($validated['payment_method_hint']) ? trim((string) $validated['payment_method_hint']) : null,
        ]);

        return redirect()->route('affiliate-network-accounts.index')
            ->with('success', 'Affiliate network account saved successfully.');
    }

    /**
     * Update the specified affiliate network account.
     */
    public function update(Request $request, AffiliateNetworkAccount $affiliateNetworkAccount): RedirectResponse
    {
        $validated = $request->validate([
            'network' => ['required', Rule::enum(AffiliateNetwork::class)],
            'network_custom' => ['nullable', 'string', 'max:100', 'required_if:network,other'],
            'nickname' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:1', 'max:500'],
            'account_id' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::enum(AffiliateNetworkAccountStatus::class)],
            'login_url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'payment_method_hint' => ['nullable', 'string', 'max:255'],
        ]);

        $network = AffiliateNetwork::from($validated['network']);
        $networkCustom = $network === AffiliateNetwork::Other
            ? trim((string) ($validated['network_custom'] ?? ''))
            : null;

        $currency = ! empty($validated['default_currency'])
            ? strtoupper(trim((string) $validated['default_currency']))
            : null;

        $dataToUpdate = [
            'network' => $network,
            'network_custom' => $networkCustom ?: null,
            'nickname' => trim((string) $validated['nickname']),
            'email' => ! empty($validated['email']) ? strtolower(trim((string) $validated['email'])) : null,
            'username' => ! empty($validated['username']) ? trim((string) $validated['username']) : null,
            'account_id' => ! empty($validated['account_id']) ? trim((string) $validated['account_id']) : null,
            'status' => AffiliateNetworkAccountStatus::from($validated['status']),
            'login_url' => ! empty($validated['login_url']) ? trim((string) $validated['login_url']) : null,
            'notes' => $validated['notes'] ?? null,
            'default_currency' => $currency,
            'payment_method_hint' => ! empty($validated['payment_method_hint']) ? trim((string) $validated['payment_method_hint']) : null,
        ];

        // Only update password if a new value is entered
        if (! empty($validated['password'])) {
            $dataToUpdate['password'] = $validated['password'];
        }

        $affiliateNetworkAccount->update($dataToUpdate);

        return redirect()->route('affiliate-network-accounts.index')
            ->with('success', 'Affiliate network account updated successfully.');
    }

    /**
     * Remove the specified affiliate network account from storage.
     */
    public function destroy(AffiliateNetworkAccount $affiliateNetworkAccount): RedirectResponse
    {
        $name = $affiliateNetworkAccount->nickname;
        $affiliateNetworkAccount->delete();

        return redirect()->route('affiliate-network-accounts.index')
            ->with('success', "Affiliate account ({$name}) deleted successfully.");
    }

    /**
     * Reveal decrypted password for authorized users.
     * Rate limited by throttle middleware. Never logs password contents.
     */
    public function revealPassword(Request $request, AffiliateNetworkAccount $affiliateNetworkAccount): JsonResponse
    {
        $decrypted = $affiliateNetworkAccount->revealPassword();

        return response()->json([
            'ok' => true,
            'password' => $decrypted ?? '',
        ]);
    }
}
