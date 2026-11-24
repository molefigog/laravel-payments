<?php

namespace Molefigog\Payments\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Molefigog\Payments\Support\Charge;
use Molefigog\Payments\Support\GatewayResponse;
use Molefigog\Payments\Support\Msisdn;
use Throwable;

/** Chaperone CPay: mobile (OTP) and card (redirect / iframe). */
class CpayGateway extends AbstractGateway
{
    protected const OK_CODES = ['0000', '200'];

    public function initiate(Charge $charge): GatewayResponse
    {
        $method = (string) ($charge->options['method'] ?? $this->setting('method', 'mobile'));

        return $method === 'card' ? $this->initiateCard($charge) : $this->initiateMobile($charge);
    }

    protected function initiateMobile(Charge $charge): GatewayResponse
    {
        $msisdn = $this->msisdn($charge);
        $amount = $this->money($charge->amount);

        $payload = ['transactionRequest' => [
            'extTransactionId' => $charge->reference,
            'clientCode' => $this->cred('client_code'),
            'msisdn' => $msisdn,
            'amount' => $amount,
            'shortDescription' => $this->clean($charge->description ?: 'payment'),
            'checksum' => $this->checksum($charge->reference.$this->cred('client_code').$amount.$msisdn),
            'currency' => $charge->currency,
            'otpMedium' => $this->setting('otp_medium', 'sms'),
            'redirectUrl' => '',
        ]];

        $response = $this->request('application/json')->post($this->base().'/api/cpaypayments/payment', $payload);
        $json = $response->json() ?? [];
        $r = $json['return'] ?? [];

        Log::info('CPay payment response', ['reference' => $charge->reference, 'code' => $r['StatusCode'] ?? null]);

        if (in_array((string) ($r['StatusCode'] ?? ''), self::OK_CODES, true)) {
            return GatewayResponse::pending(
                $r['Description'] ?? 'Enter the OTP sent to your phone.',
                'otp',
                $json,
                null,
                (string) ($r['ExtTransactionId'] ?? $charge->reference),
            );
        }

        return GatewayResponse::failed($r['Description'] ?? 'CPay could not start the payment.', $json, $r['ReasonCode'] ?? null);
    }

    protected function initiateCard(Charge $charge): GatewayResponse
    {
        $msisdn = $this->msisdn($charge);
        $amount = $this->money($charge->amount);
        $email = $charge->options['email'] ?? $this->setting('default_email') ?? config('mail.from.address');

        $payload = ['transactionRequest' => [
            'extTransactionId' => $charge->reference,
            'clientCode' => $this->cred('client_code'),
            'msisdn' => $msisdn,
            'amount' => $amount,
            'checksum' => $this->checksum($charge->reference.$this->cred('client_code').$amount.$msisdn),
            'currency' => $charge->currency,
            'redirectUrl' => $this->callbackUrl($charge),
            'shortDescription' => '',
            'otpMedium' => $this->setting('otp_medium', 'sms'),
        ]];

        $url = $this->base().'/api/cpaypayments/payment?cardPayment=true&rememberMe=false&email='.urlencode((string) $email);
        $response = $this->request('text/plain')->post($url, $payload);

        $body = $response->body();
        $redirect = $response->header('redirecturl') ?: null;
        $json = json_decode($body, true);

        if (str_contains($body, '<iframe')) {
            return GatewayResponse::pending('Complete the card payment.', 'html', ['card' => 'html'], null, null, $redirect, $body);
        }

        if ($redirect) {
            return GatewayResponse::pending('Complete the card payment.', 'redirect', ['card' => $json], null, null, $redirect);
        }

        return GatewayResponse::failed($json['Description'] ?? 'CPay card payment could not be started.', ['card' => $json ?? $body], $json['StatusCode'] ?? null);
    }

    public function confirm(Charge $charge, array $input): GatewayResponse
    {
        $otp = trim((string) ($input['otp'] ?? ''));
        if ($otp === '') {
            return GatewayResponse::pending('An OTP is required.', 'otp');
        }

        $msisdn = $this->msisdn($charge);
        $amount = $this->money($charge->amount);

        $payload = ['transactionRequest' => [
            'extTransactionId' => $charge->reference,
            'clientCode' => $this->cred('client_code'),
            'msisdn' => $msisdn,
            'amount' => $amount,
            'otp' => $otp,
            'checksum' => $this->checksum($charge->reference.$this->cred('client_code').$amount.$msisdn.$otp),
            'currency' => $charge->currency,
        ]];

        $json = $this->request('application/json')->post($this->base().'/api/cpaypayments/confirm', $payload)->json() ?? [];

        if (in_array((string) ($json['statusCode'] ?? ''), self::OK_CODES, true)) {
            return GatewayResponse::completed(
                $json['description'] ?? 'Payment completed',
                $json,
                null,
                isset($json['cPayTransactionId']) ? (string) $json['cPayTransactionId'] : null,
            );
        }

        return GatewayResponse::failed($json['description'] ?? 'The payment could not be confirmed.', $json, $json['statusCode'] ?? null);
    }

    public function status(Charge $charge): GatewayResponse
    {
        try {
            $json = $this->request('application/json')->get($this->base().'/api/cpaypayments/transaction-status', [
                'requestReference' => $charge->reference,
                'dateTime' => now()->format('Y-m-d'),
            ])->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('CPay status check failed', ['reference' => $charge->reference, 'error' => $e->getMessage()]);

            return GatewayResponse::pending();
        }

        // The status payload shape is not documented in the original code: only treat the
        // documented completion marker as success, anything else stays pending.
        $reason = $json['ReasonCode'] ?? $json['return']['ReasonCode'] ?? null;

        return $reason === 'TransactionComplete'
            ? GatewayResponse::completed('Payment completed', $json)
            : GatewayResponse::pending(null, 'none', array_merge($charge->raw, ['status' => $json]));
    }

    public function callbackReference(array $payload): ?string
    {
        $ref = $payload['ExtTransactionId'] ?? $payload['extTransactionId'] ?? $payload['reference'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    public function callback(Charge $charge, array $payload): GatewayResponse
    {
        // NOTE: CPay's callback is unsigned in the original integration. We only accept it for a
        // reference that exists and is still pending; confirm signature options with Chaperone.
        $paid = ($payload['ReasonCode'] ?? null) === 'TransactionComplete';
        $cpayId = isset($payload['CPayTransactionId']) ? (string) $payload['CPayTransactionId'] : null;

        return $paid
            ? GatewayResponse::completed('Payment completed', $payload, null, $cpayId)
            : GatewayResponse::failed($payload['Description'] ?? 'Payment was not completed.', $payload, $payload['ReasonCode'] ?? null);
    }

    protected function request(string $accept): PendingRequest
    {
        $request = Http::withHeaders([
            'Authorization' => (string) $this->cred('api_key'),
            'Accept' => $accept,
        ])->withOptions(['verify' => filter_var($this->setting('verify_ssl', true), FILTER_VALIDATE_BOOLEAN)])
            ->timeout(30);

        return $request->contentType('application/json-patch+json')->bodyFormat('json');
    }

    protected function msisdn(Charge $charge): string
    {
        return Msisdn::format((string) $charge->msisdn, (string) $this->setting('msisdn_format', 'digits'));
    }

    protected function checksum(string $salt): string
    {
        return hash_hmac('sha256', $salt, (string) $this->cred('secret'));
    }

    protected function base(): string
    {
        return rtrim((string) $this->setting('base_url'), '/');
    }

    protected function clean(string $value): string
    {
        return trim(mb_convert_encoding($value, 'UTF-8', 'UTF-8'));
    }

    public function pollInterval(): ?int
    {
        return null; // CPay is OTP/callback driven; no polling job by default
    }
}
