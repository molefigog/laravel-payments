<?php

namespace Molefigog\Payments\Contracts;

use Molefigog\Payments\Support\Charge;
use Molefigog\Payments\Support\GatewayResponse;

interface PaymentGateway
{
    /** Start the payment with the provider. Must not mark anything paid unless the provider confirmed it. */
    public function initiate(Charge $charge): GatewayResponse;

    /** Second step for flows that need it (CPay OTP, PayPal capture from the JS SDK). */
    public function confirm(Charge $charge, array $input): GatewayResponse;

    /** Ask the provider for the current state. Used for polling and reconciliation. */
    public function status(Charge $charge): GatewayResponse;

    /** Pull our reference (or the provider id we stored) out of a callback payload. */
    public function callbackReference(array $payload): ?string;

    /** Handle a provider callback / browser return for an existing charge. */
    public function callback(Charge $charge, array $payload): GatewayResponse;

    /** Seconds between status polls, or null when this gateway is not polled. */
    public function pollInterval(): ?int;

    /** Give up on a pending charge after this many seconds, or null for never. */
    public function timeoutSeconds(): ?int;

    /** Non-secret values a frontend may need (e.g. PayPal client id). */
    public function publicConfig(): array;
}
