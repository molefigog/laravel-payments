<?php

namespace Molefigog\Payments\Gateways;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Molefigog\Payments\Support\Charge;
use Molefigog\Payments\Support\GatewayResponse;
use RuntimeException;
use stdClass;
use Throwable;

class PaypalGateway extends AbstractGateway
{
    public function initiate(Charge $charge): GatewayResponse
    {
        $paypalCurrency = strtoupper((string) $this->setting('paypal_currency', 'USD'));
        $fx = $this->convert($charge->amount, strtoupper($charge->currency), $paypalCurrency);

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $charge->reference,
                'custom_id' => $charge->reference,
                'description' => mb_substr((string) ($charge->description ?: 'Purchase'), 0, 127),
                'amount' => ['currency_code' => $paypalCurrency, 'value' => $this->money($fx['amount'])],
            ]],
            'application_context' => [
                'return_url' => $this->callbackUrl($charge),
                'cancel_url' => $this->callbackUrl($charge, ['cancelled' => 1]),
                'landing_page' => 'BILLING',
                'user_action' => 'PAY_NOW',
            ],
        ];

        $order = $this->api()->post($this->base().'/v2/checkout/orders', $payload)->json() ?? [];
        $data = [
            'order' => $order,
            'fx' => $fx,
            'expected' => ['currency' => $paypalCurrency, 'value' => $this->money($fx['amount'])],
        ];

        if (empty($order['id'])) {
            return GatewayResponse::failed($order['message'] ?? 'PayPal could not create the order.', $data);
        }

        $approve = null;
        foreach ((array) ($order['links'] ?? []) as $link) {
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)) {
                $approve = $link['href'] ?? null;
                break;
            }
        }

        return GatewayResponse::pending('Approve the payment on PayPal.', 'redirect', $data, (string) $order['id'], null, $approve);
    }

    /** JS-SDK flow: the frontend approved the order and sends order_id back for capture. */
    public function confirm(Charge $charge, array $input): GatewayResponse
    {
        $orderId = (string) ($input['order_id'] ?? $charge->txnId);

        if (! $charge->hasProviderTxn() || $orderId !== $charge->txnId) {
            return GatewayResponse::failed('That PayPal order does not belong to this payment.');
        }

        return $this->capture($charge, $orderId);
    }

    public function callback(Charge $charge, array $payload): GatewayResponse
    {
        if (! empty($payload['cancelled'])) {
            return GatewayResponse::failed('Payment was cancelled.', array_merge($charge->raw, ['cancelled' => true]), 'cancelled');
        }

        if (! $charge->hasProviderTxn()) {
            return GatewayResponse::pending();
        }

        return $this->capture($charge, (string) $charge->txnId);
    }

    public function status(Charge $charge): GatewayResponse
    {
        if (! $charge->hasProviderTxn()) {
            return GatewayResponse::pending();
        }

        $order = $this->api()->get($this->base().'/v2/checkout/orders/'.$charge->txnId)->json() ?? [];
        $state = strtoupper((string) ($order['status'] ?? ''));
        $data = array_merge($charge->raw, ['order' => $order]);

        return match (true) {
            $state === 'COMPLETED' => $this->verifyAmount($charge, $order, $data),
            in_array($state, ['VOIDED'], true) => GatewayResponse::failed('Order was voided.', $data, $state),
            default => GatewayResponse::pending(null, 'none', $data),
        };
    }

    protected function capture(Charge $charge, string $orderId): GatewayResponse
    {
        try {
            $response = $this->api()
                ->withHeaders(['PayPal-Request-Id' => $orderId]) // idempotent capture
                ->withBody('{}', 'application/json')
                ->post($this->base()."/v2/checkout/orders/$orderId/capture");
            $result = $response->json() ?? [];
        } catch (Throwable $e) {
            Log::error('PayPal capture exception', ['order' => $orderId, 'error' => $e->getMessage()]);

            return GatewayResponse::pending('Capture failed; will retry.');
        }

        $data = array_merge($charge->raw, ['capture' => $result]);

        if (strtoupper((string) ($result['status'] ?? '')) === 'COMPLETED') {
            return $this->verifyAmount($charge, $result, $data);
        }

        // Already captured earlier (retry)? Ask the order itself before declaring failure.
        $order = $this->status($charge);
        if ($order->isCompleted()) {
            return $order;
        }

        return GatewayResponse::failed($result['message'] ?? 'PayPal did not complete the payment.', $data, $result['name'] ?? null);
    }

    /** Never trust "COMPLETED" alone: the captured amount must equal what we asked for. */
    protected function verifyAmount(Charge $charge, array $order, array $data): GatewayResponse
    {
        $expected = $charge->raw['expected'] ?? null;
        $captured = $order['purchase_units'][0]['payments']['captures'][0]['amount'] ?? null;
        $payerId = $order['payer']['payer_id'] ?? null;

        if ($expected && $captured
            && ((string) $captured['value'] !== (string) $expected['value'] || strtoupper((string) $captured['currency_code']) !== strtoupper((string) $expected['currency']))
        ) {
            Log::error('PayPal amount mismatch', ['reference' => $charge->reference, 'expected' => $expected, 'captured' => $captured]);

            return GatewayResponse::failed('Captured amount does not match the order.', $data, 'amount_mismatch');
        }

        return GatewayResponse::completed('Payment completed', $data, null, $payerId ? (string) $payerId : null);
    }

    /** @return array{amount: float, rate: float, source: string} */
    protected function convert(float $amount, string $from, string $to): array
    {
        if ($from === $to) {
            return ['amount' => round($amount, 2), 'rate' => 1.0, 'source' => 'none'];
        }

        $source = strtoupper((string) ($this->setting('fx_source_currency') ?: $from));
        $markup = (float) $this->setting('fx_markup', 0.04);
        $rate = null;
        $origin = 'api';

        try {
            $rate = Cache::remember("payments.fx.$source.$to", 3600, function () use ($source, $to) {
                $url = "https://open.er-api.com/v6/latest/$source";
                if ($key = $this->setting('fx_api_key')) {
                    $url .= '?apikey='.urlencode((string) $key);
                }
                $json = Http::timeout(10)->acceptJson()->get($url)->json();
                if (! isset($json['rates'][$to])) {
                    throw new RuntimeException('rate missing');
                }

                return (float) $json['rates'][$to];
            });
        } catch (Throwable $e) {
            Log::warning('PayPal FX lookup failed', ['error' => $e->getMessage()]);
        }

        if ($rate === null) {
            $fallback = $this->setting('fx_fallback_rate');
            if ($fallback === null || (float) $fallback <= 0) {
                throw new RuntimeException('Currency conversion is unavailable.');
            }
            $rate = (float) $fallback;
            $origin = 'fallback';
        }

        return ['amount' => round(max(0.01, $amount * $rate * (1 + $markup)), 2), 'rate' => $rate, 'source' => $origin];
    }

    protected function api()
    {
        return Http::withToken($this->token())->acceptJson()->timeout(30);
    }

    protected function token(): string
    {
        $response = Http::asForm()
            ->withBasicAuth((string) $this->cred('client_id'), (string) $this->cred('secret'))
            ->timeout(30)
            ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');
        if (! $token) {
            throw new RuntimeException('Unable to authenticate with PayPal.');
        }

        return $token;
    }

    protected function base(): string
    {
        return $this->isLive() ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function publicConfig(): array
    {
        return [
            'client_id' => $this->cred('client_id'),
            'environment' => $this->isLive() ? 'live' : 'sandbox',
        ];
    }

    public function pollInterval(): ?int
    {
        return null; // redirect / SDK driven
    }
}
