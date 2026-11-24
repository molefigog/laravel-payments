<?php

namespace Molefigog\Payments\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Minimal Vodacom M-Pesa OpenAPI client (c2b + query). Ported from the working Musik VclController,
 * without the phpseclib dependency: the session/api key is encrypted with the provider's public key.
 */
class MpesaClient
{
    public const BASE_DOMAIN = 'https://openapi.m-pesa.com';

    protected PendingRequest $http;

    /** @param array{api_key:string,public_key:string,env?:string,market?:string,service_provider_code?:?string,country?:string,currency?:string} $options */
    public function __construct(protected array $options)
    {
        foreach (['api_key', 'public_key'] as $required) {
            if (empty($options[$required])) {
                throw new InvalidArgumentException("M-Pesa $required is not configured.");
            }
        }

        $env = ($options['env'] ?? 'sandbox') === 'live' ? 'openapi' : 'sandbox';
        $market = $options['market'] ?? 'vodacomLES';

        $this->http = Http::baseUrl(self::BASE_DOMAIN."/$env/ipg/v2/$market/")
            ->acceptJson()
            ->withHeaders(['Origin' => '*'])
            ->timeout(30);
    }

    public function c2b(array $data): array
    {
        $token = $this->encrypt($this->sessionId());

        return $this->http
            ->withHeaders(['Authorization' => "Bearer $token"])
            ->post('c2bPayment/singleStage/', $this->withDefaults($data))
            ->json() ?? [];
    }

    public function query(array $data): array
    {
        $token = $this->encrypt($this->sessionId());

        return $this->http
            ->withHeaders(['Authorization' => "Bearer $token"])
            ->get('queryTransactionStatus/', $this->withDefaults($data))
            ->json() ?? [];
    }

    protected function sessionId(): string
    {
        $response = $this->http
            ->withHeaders(['Authorization' => 'Bearer '.$this->encrypt($this->options['api_key'])])
            ->get('getSession/')
            ->json() ?? [];

        if (($response['output_ResponseCode'] ?? null) === 'INS-0' && ! empty($response['output_SessionID'])) {
            return $response['output_SessionID'];
        }

        throw new RuntimeException($response['output_ResponseDesc'] ?? 'Unable to start an M-Pesa session.');
    }

    protected function withDefaults(array $data): array
    {
        $data['input_ServiceProviderCode'] ??= $this->options['service_provider_code'] ?? null;
        $data['input_Country'] ??= $this->options['country'] ?? 'LES';
        $data['input_Currency'] ??= $this->options['currency'] ?? 'LSL';

        return $data;
    }

    protected function encrypt(string $value): string
    {
        $key = trim($this->options['public_key']);

        if (! str_contains($key, 'BEGIN')) {
            $key = "-----BEGIN PUBLIC KEY-----\n".chunk_split(preg_replace('/\s+/', '', $key), 64, "\n").'-----END PUBLIC KEY-----';
        }

        $public = openssl_pkey_get_public($key);
        if ($public === false || ! openssl_public_encrypt($value, $encrypted, $public, OPENSSL_PKCS1_PADDING)) {
            throw new RuntimeException('Could not encrypt the M-Pesa request key. Check the public key.');
        }

        return base64_encode($encrypted);
    }
}
