<?php

namespace Molefigog\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Molefigog\Payments\Exceptions\PaymentException;
use Molefigog\Payments\GatewayManager;
use Molefigog\Payments\PaymentService;
use Molefigog\Payments\Support\ChargeRequest;
use Molefigog\Payments\Support\ChargeResult;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payments, protected GatewayManager $gateways)
    {
    }

    public function gateways(): JsonResponse
    {
        return response()->json(['data' => $this->gateways->enabled()->map(function (array $d) {
            $public = [];
            try {
                $public = $this->gateways->driver($d['slug'])->publicConfig();
            } catch (\Throwable) {
            }

            return [
                'slug' => $d['slug'],
                'name' => $d['name'],
                'driver' => $d['driver'],
                'currency' => $d['currency'],
                'public' => $public,
            ];
        })->values()]);
    }

    public function charge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gateway' => 'required|string|max:64',
            'product_id' => 'nullable',
            'product_ids' => 'nullable|array',
            'amount' => 'nullable|numeric|min:0.01',
            'msisdn' => 'nullable|string|max:32',
            'description' => 'nullable|string|max:255',
            'currency' => 'nullable|string|size:3',
            'return_url' => 'nullable|string|max:2048',
            'email' => 'nullable|email',
            'method' => 'nullable|string|in:mobile,card',
            'meta' => 'nullable|array',
        ]);

        $products = $data['product_ids'] ?? $data['product_id'] ?? null;

        try {
            $result = $this->payments->charge(
                ChargeRequest::make($data['gateway'], $products, $data, $request->user()?->getAuthIdentifier())
            );
        } catch (PaymentException $e) {
            return response()->json(['success' => false, 'status' => 'failed', 'message' => $e->getMessage()], 422);
        }

        return $this->respond($result);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:128',
            'otp' => 'nullable|string|max:32',
            'order_id' => 'nullable|string|max:128',
        ]);

        $this->authorizeReference($request, $data['reference']);

        try {
            return $this->respond($this->payments->confirm($data['reference'], $request->only(['otp', 'order_id'])));
        } catch (PaymentException $e) {
            return response()->json(['success' => false, 'status' => 'failed', 'message' => $e->getMessage()], 404);
        }
    }

    public function status(Request $request, string $reference): JsonResponse
    {
        $this->authorizeReference($request, $reference);

        $result = $this->payments->refresh($reference);

        abort_if($result === null, 404, 'Payment not found.');

        return $this->respond($result);
    }

    /** A payment owned by a user may only be read/driven by that user. Guest payments rely on the unguessable reference. */
    protected function authorizeReference(Request $request, string $reference): void
    {
        $rows = $this->payments->rows($reference);
        abort_if($rows->isEmpty(), 404, 'Payment not found.');

        $owner = $rows->first()->user_id;
        if ($owner !== null) {
            abort_unless($request->user() && (string) $request->user()->getAuthIdentifier() === (string) $owner, 403);
        }
    }

    protected function respond(ChargeResult $result): JsonResponse
    {
        return response()->json($result->toArray(), $result->success() ? 200 : 422);
    }
}
