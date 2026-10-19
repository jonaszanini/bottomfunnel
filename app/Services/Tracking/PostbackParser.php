<?php

namespace App\Services\Tracking;

class PostbackParser
{
    public function parse(string $rawBody, array $query, string $secret, array $headers = []): ParsedPostback
    {
        $fields = $this->fields($rawBody, $query);
        $orderId = $this->first($fields, [
            'order_id', 'orderid', 'order', 'receipt', 'transaction_id', 'transactionid',
            'txn_id', 'sale_id', 'purchase_id', 'cb_receipt', 'event_id',
        ]);
        if ($orderId === null || $orderId === '') {
            $orderId = 'missing-'.sha1($rawBody !== '' ? $rawBody : http_build_query($query));
        }

        $amount = $this->first($fields, [
            'commission', 'affiliate_commission', 'affiliate_amount', 'amount_affiliate',
            'accountamount', 'account_amount', 'payout', 'earnings', 'amount',
        ]);
        $currency = strtoupper((string) ($this->first($fields, ['currency', 'currency_code', 'cy']) ?: 'USD'));
        $gclid = $this->first($fields, ['gclid']);
        $session = $this->first($fields, ['tid', 'sid', 'subid', 'subid1', 's2', 'click_id', 'session_id', 'session']);
        $tokens = array_values(array_unique(array_filter([
            $session,
            $this->first($fields, ['tid']),
            $this->first($fields, ['sid']),
            $this->first($fields, ['subid']),
            $this->first($fields, ['subid1']),
            $this->first($fields, ['custom']),
        ])));

        $event = strtolower((string) ($this->first($fields, ['transaction_type', 'transactiontype', 'txn_type', 'event', 'type', 'status']) ?: 'sale'));
        $eventType = 'sale';
        if (str_contains($event, 'charge') || $event === 'cgbk') {
            $eventType = 'chargeback';
        } elseif (str_contains($event, 'refund') || $event === 'rfnd' || str_contains($event, 'on_refund')) {
            $eventType = 'refund';
        }

        $signature = $this->signature($fields, $rawBody, $secret, $headers);

        return new ParsedPostback(
            orderId: $orderId,
            amount: is_numeric($amount) ? (float) $amount : 0.0,
            currency: strlen($currency) === 3 ? $currency : 'USD',
            gclid: $gclid,
            sessionToken: $session,
            tokens: $tokens,
            eventType: $eventType,
            signaturePresent: $signature['present'],
            signatureValid: $signature['valid'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function fields(string $rawBody, array $query): array
    {
        $parsed = [];
        $trim = ltrim($rawBody);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $json = json_decode($rawBody, true);
            if (is_array($json)) {
                $parsed = $this->flatten($json);
            }
        }
        if ($parsed === [] && $rawBody !== '') {
            $form = [];
            parse_str($rawBody, $form);
            if (is_array($form) && $form !== []) {
                $parsed = $this->flatten($form);
            }
        }

        return array_merge($this->flatten($query), $parsed);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, string>
     */
    private function flatten(array $source): array
    {
        $flat = [];
        foreach ($source as $key => $value) {
            if (is_array($value)) {
                foreach ($this->flatten($value) as $childKey => $child) {
                    $flat[strtolower((string) $key.'.'.$childKey)] = $child;
                    $flat[strtolower((string) $childKey)] = $child;
                }

                continue;
            }
            if (is_scalar($value) || $value === null) {
                $flat[strtolower((string) $key)] = trim((string) $value);
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  list<string>  $keys
     */
    private function first(array $fields, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($fields[$key]) && $fields[$key] !== '') {
                return $fields[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, mixed>  $headers
     * @return array{present: bool, valid: bool}
     */
    private function signature(array $fields, string $rawBody, string $secret, array $headers): array
    {
        $headerSignature = $this->header($headers, 'x-signature')
            ?? $this->header($headers, 'x-clickbank-signature')
            ?? $this->header($headers, 'x-hub-signature-256');
        if ($headerSignature !== null) {
            $expected = hash_hmac('sha256', $rawBody, $secret);
            $given = str_starts_with($headerSignature, 'sha256=') ? substr($headerSignature, 7) : $headerSignature;

            return ['present' => true, 'valid' => hash_equals($expected, strtolower($given))];
        }

        if (isset($fields['sha_sign']) && $fields['sha_sign'] !== '') {
            return ['present' => true, 'valid' => hash_equals($this->digistoreSignature($fields, $secret), strtoupper($fields['sha_sign']))];
        }

        return ['present' => false, 'valid' => true];
    }

    /**
     * @param  array<string, string>  $fields
     */
    public function digistoreSignature(array $fields, string $passphrase): string
    {
        unset($fields['sha_sign']);
        ksort($fields);
        $sha = '';
        foreach ($fields as $key => $value) {
            if ($value === '') {
                continue;
            }
            $sha .= $key.'='.$value.$passphrase;
        }

        return strtoupper(hash('sha512', $sha));
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }
}
