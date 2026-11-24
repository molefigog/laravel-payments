<?php

namespace Molefigog\Payments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Molefigog\Payments\Exceptions\PaymentException;
use Molefigog\Payments\PaymentService;
use Molefigog\Payments\Support\RedirectGuard;

class CallbackController extends Controller
{
    public function __invoke(Request $request, PaymentService $payments, string $gateway)
    {
        try {
            $result = $payments->handleCallback($gateway, $request->all());
        } catch (PaymentException) {
            return response()->json(['success' => false, 'message' => 'Unknown gateway.'], 404);
        }

        if ($result === null) {
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        // Browser returns (PayPal, CPay card) are sent back to the app; server-to-server calls get JSON.
        $target = $result->payments->first()->meta['options']['return_url'] ?? config('payments.result_url');

        if (! $request->wantsJson() && RedirectGuard::isAllowed($target)) {
            return redirect()->away(
                RedirectGuard::append($target, ['reference' => $result->reference, 'status' => $result->status]),
                303
            );
        }

        return response()->json($result->toArray());
    }
}
