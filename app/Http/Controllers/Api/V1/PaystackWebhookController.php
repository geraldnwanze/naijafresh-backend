<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaystackPaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    /**
     * Paystack posts transaction events here. The signature header is verified
     * against the secret key before anything is trusted.
     */
    public function __invoke(Request $request, PaystackPaymentGateway $gateway, PaymentService $payments)
    {
        $event = $gateway->parseWebhook(
            $request->getContent(),
            $request->header('x-paystack-signature', ''),
        );

        if ($event === null) {
            return response()->json(['message' => 'Ignored.'], 202);
        }

        $payment = Payment::where('reference', $event['reference'])->first();

        if ($payment === null) {
            Log::channel('analytics')->warning('paystack_webhook_unknown_reference', [
                'reference' => $event['reference'],
            ]);

            return response()->json(['message' => 'Unknown reference.'], 202);
        }

        $payments->applyVerification($payment, $event['verification']);

        return response()->json(['message' => 'Processed.']);
    }
}
