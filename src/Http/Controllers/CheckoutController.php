<?php

namespace Stephenjude\PaymentGateway\Http\Controllers;

use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Stephenjude\PaymentGateway\PaymentGateway;
use Symfony\Component\HttpFoundation\Response;

class CheckoutController extends Controller
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    public function __invoke(Request $request, string $provider, string $reference)
    {
        try {
            $paymentProvider = PaymentGateway::make($provider);

            $sessionData = $paymentProvider->getInitializedPayment($reference);

            abort_if(is_null($sessionData), Response::HTTP_BAD_REQUEST, 'Payment session has expired');

            return view("payment-gateways::checkout.$sessionData->provider", [
                'sessionData' => $sessionData->toArray(),
            ]);

        } catch (Exception $exception) {
            logger($exception->getMessage(), $exception->getTrace());

            abort(Response::HTTP_BAD_REQUEST, "Payment Error: ".$exception->getMessage());
        }
    }
}
