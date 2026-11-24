<?php

namespace Molefigog\Payments\Gateways;

use Illuminate\Support\Facades\Log;
use Molefigog\Payments\Support\Charge;
use Molefigog\Payments\Support\GatewayResponse;
use Molefigog\Payments\Support\Msisdn;
use Throwable;

class MpesaGateway extends AbstractGateway
{
    public function initiate(Charge $charge): GatewayResponse
    {
        $msisdn = Msisdn::format(
            (string) $charge->msisdn,
            (string) $this->setting('msisdn_format', 'international'),
            (string) $this->setting('msisdn_prefix', '266'),
        );

        $response = $this->client($charge)->c2b([
            'input_Amount' => $this->money($charge->amount),
            'input_Country' => $this->setting('country', 'LES'),
            'input_Currency' => $charge->currency,
            'input_CustomerMSISDN' => $msisdn,
            'input_ServiceProviderCode' => $this->cred('short_code'),
            'input_ThirdPartyConversationID' => $charge->reference,
            'input_TransactionReference' => (string) $this->setting('transaction_reference', 'payment'),
            'input_PurchasedItemsDesc' => (string) ($charge->description ?: 'Purchase'),
        ]);

        Log::info('M-Pesa charge response', ['reference' => $charge->reference, 'code' => $response['output_ResponseCode'] ?? null]);

        $data = ['charge' => $response, 'query' => null];

        if (($response['output_ResponseCode'] ?? null) === 'INS-0') {
            return GatewayResponse::pending(
                message: 'Approve the payment on your phone.',
                action: 'poll',
                data: $data,
                conversationId: $charge->reference,
            );
        }

        return GatewayResponse::failed(
            $response['output_ResponseDesc'] ?? 'M-Pesa could not start the payment.',
            $data,
            $response['output_ResponseCode'] ?? null,
        );
    }

    public function status(Charge $charge): GatewayResponse
    {
        $chargePayload = $charge->raw['charge'] ?? null;
        $queryReference = $this->queryReference($charge, $chargePayload);

        try {
            $query = $this->client($charge)->query([
                'input_QueryReference' => $queryReference,
                'input_Country' => $this->setting('country', 'LES'),
                'input_ServiceProviderCode' => $this->cred('short_code'),
                'input_ThirdPartyConversationID' => $charge->conversationId ?: $charge->reference,
            ]);
        } catch (Throwable $e) {
            Log::warning('M-Pesa status query failed', ['reference' => $charge->reference, 'error' => $e->getMessage()]);

            return GatewayResponse::pending('Status check failed; will retry.');
        }

        $data = [
            'charge' => $chargePayload,
            'query' => $query,
            'query_reference' => $queryReference,
        ];

        $txnId = $query['output_OriginalTransactionID'] ?? null;
        $status = $this->normalize($query, $charge);
        $message = $query['output_ResponseDesc'] ?? null;

        return match ($status) {
            'completed' => GatewayResponse::completed($message, $data, $txnId),
            'failed' => GatewayResponse::failed($message, $data, $query['output_ResponseCode'] ?? null, $txnId),
            default => GatewayResponse::pending($message, 'none', $data, $txnId),
        };
    }

    /** Same rules as the original PollMpesaTransactionStatus job. */
    protected function normalize(array $query, Charge $charge): string
    {
        $code = strtoupper((string) ($query['output_ResponseCode'] ?? ''));
        $raw = strtolower((string) ($query['output_ResponseTransactionStatus'] ?? ''));
        $transaction = preg_replace('/[^a-z0-9]+/', '', $raw) ?? '';
        $withinGrace = $charge->ageSeconds() < max(30, (int) $this->setting('grace_seconds', 240));

        if (in_array($code, ['INS-6', 'INS-2006', 'INS-2051'], true)) {
            return 'failed';
        }

        // Transient while the provider's query index catches up.
        if (in_array($code, ['INS-23', 'INS-6140'], true)) {
            return $withinGrace ? 'pending' : 'failed';
        }

        if ($code === 'INS-0' && in_array($transaction, ['', 'completed', 'success', 'successful', 'transactioncomplete'], true)) {
            return 'completed';
        }

        if (in_array($transaction, ['failed', 'cancelled', 'declined', 'rejected', 'reversed'], true)) {
            return 'failed';
        }

        if (in_array($transaction, ['timeout', 'timedout', 'na'], true)) {
            return $withinGrace ? 'pending' : 'failed';
        }

        return 'pending';
    }

    protected function queryReference(Charge $charge, ?array $chargePayload): string
    {
        foreach (['output_ConversationID', 'output_TransactionID'] as $key) {
            $value = (string) ($chargePayload[$key] ?? '');
            if ($value !== '' && strtoupper($value) !== 'N/A') {
                return $value;
            }
        }

        return $charge->conversationId ?: $charge->reference;
    }

    protected function client(Charge $charge): MpesaClient
    {
        return new MpesaClient([
            'api_key' => (string) $this->cred('api_key'),
            'public_key' => (string) $this->cred('public_key'),
            'service_provider_code' => $this->cred('short_code'),
            'env' => $this->isLive() ? 'live' : 'sandbox',
            'market' => $this->setting('market', 'vodacomLES'),
            'country' => $this->setting('country', 'LES'),
            'currency' => $charge->currency,
        ]);
    }

    public function pollInterval(): ?int
    {
        return (int) $this->setting('poll_interval', 15);
    }
}
