<?php

namespace Molefigog\Payments\Gateways;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Molefigog\Payments\Support\Charge;
use Molefigog\Payments\Support\GatewayResponse;
use Molefigog\Payments\Support\Msisdn;
use Throwable;

/** EcoCash Lesotho through the PayLesotho merchant API. */
class EcocashGateway extends AbstractGateway
{
    public function initiate(Charge $charge): GatewayResponse
    {
        $response = Http::withToken((string) $this->cred('token'))
            ->acceptJson()
            ->timeout(30)
            ->post($this->base().'/api/v2/econet/payment', [
                'merchantid' => $this->cred('merchant_id'),
                'amount' => round($charge->amount, 2),
                'mobileNumber' => Msisdn::format((string) $charge->msisdn, (string) $this->setting('msisdn_format', 'raw')),
                'merchantname' => $this->cred('merchant_name'),
                'client_reference' => ($charge->userId ?? 'guest').'_'.$charge->reference,
            ]);

        $data = $response->json() ?? [];
        Log::info('EcoCash payment response', ['reference' => $charge->reference, 'status_code' => $data['status_code'] ?? null]);

        if ((string) ($data['status_code'] ?? '') !== '200' || empty($data['reference'])) {
            return GatewayResponse::failed($data['message'] ?? 'EcoCash could not start the payment.', ['payment' => $data]);
        }

        $providerRef = (string) $data['reference'];
        $verify = $this->verify($providerRef);
        $payload = ['payment' => $data, 'verify' => $verify];

        // Some merchants get a synchronous result; otherwise we poll until the customer approves.
        if ((string) ($verify['status_code'] ?? '') === '200') {
            return GatewayResponse::completed('Payment completed', $payload, $providerRef);
        }

        return GatewayResponse::pending('Approve the payment on your phone.', 'poll', $payload, $providerRef);
    }

    public function status(Charge $charge): GatewayResponse
    {
        if (! $charge->hasProviderTxn()) {
            return GatewayResponse::pending();
        }

        $verify = $this->verify((string) $charge->txnId);
        $payload = array_merge($charge->raw, ['verify' => $verify]);

        if ((string) ($verify['status_code'] ?? '') === '200') {
            return GatewayResponse::completed('Payment completed', $payload);
        }

        return GatewayResponse::pending($verify['message'] ?? null, 'none', $payload);
    }

    protected function verify(string $providerReference): array
    {
        try {
            return Http::withToken((string) $this->cred('token'))
                ->acceptJson()
                ->timeout(30)
                ->get($this->base().'/api/v2/econet/verify/'.$providerReference)
                ->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('EcoCash verify failed', ['ref' => $providerReference, 'error' => $e->getMessage()]);

            return [];
        }
    }

    protected function base(): string
    {
        return rtrim((string) $this->setting('base_url', 'https://api.paylesotho.co.ls'), '/');
    }

    public function pollInterval(): ?int
    {
        return (int) $this->setting('poll_interval', 5);
    }

    public function timeoutSeconds(): ?int
    {
        return (int) $this->setting('timeout_seconds', 180);
    }
}
