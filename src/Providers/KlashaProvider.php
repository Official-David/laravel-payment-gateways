<?php

namespace Stephenjude\PaymentGateway\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Stephenjude\PaymentGateway\DataObjects\PaymentTransactionData;
use Stephenjude\PaymentGateway\DataObjects\SessionData;
use Stephenjude\PaymentGateway\Exceptions\VerificationException;

class KlashaProvider extends AbstractProvider
{
    public string $provider = 'klasha';

    public function initializeTransaction(array $parameters = []): SessionData
    {
        $parameters['reference'] = 'KSA_'.Str::random(12);

        $parameters['expires'] = config('payment-gateways.cache.session.expires');

        $parameters['session_cache_key'] = config('payment-gateways.cache.session.key').$parameters['reference'];

        $sessionData = $this->initializeProvider($parameters);

        return Cache::remember($parameters['session_cache_key'], $parameters['expires'], fn () => new SessionData(
            ...$sessionData
        ));
    }

    public function confirmTransaction(string $reference, ?SerializableClosure $closure): PaymentTransactionData|null
    {
        $provider = $this->verifyTransaction($reference);

        $payment = new PaymentTransactionData(
            email: $provider['customer']['email'],
            meta: $provider['customer'],
            amount: $provider['sourceAmount'],
            currency: $provider['sourceCurrency'],
            reference: $reference,
            provider: $this->provider,
            status: $provider['status'],
            date: Carbon::now()->toDateTimeString(),
        );

        $this->executeClosure($closure, $payment);

        return $payment;
    }

    public function initializeProvider(array $parameters): mixed
    {
        return [
            'provider' => $this->provider,
            'sessionReference' => $parameters['reference'],
            'paymentReference' => $parameters['reference'],
            'checkoutSecret' => null,
            'checkoutUrl' => route(config('payment-gateways.routes.checkout.name'), [
                'reference' => $parameters['reference'],
                'provider' => $this->provider,
            ]),
            'expires' => $parameters['expires'],
            'closure' => $parameters['closure'] ? new SerializableClosure($parameters['closure']) : null,
            'extra' => [
                'email' => $parameters['email'],
                'currency' => $parameters['currency'],
                'amount' => $parameters['amount'],
                'channels' => $this->getChannels(),
                'is_test_mode' => false,
                'callback_url' => route(config('payment-gateways.routes.callback.name'), [
                    'reference' => $parameters['reference'],
                    'provider' => $this->provider,
                ]),
            ],
        ];
    }

    public function verifyTransaction(string $reference): mixed
    {
        $response = $this->getToken()->acceptJson()->post($this->baseUrl.'nucleus/tnx/merchant/status', [
            'tnxRef' => $reference,
        ]);

        $this->logResponseIfEnabledDebugMode($this->provider, $response);

        if ($response->failed()) {
            throw new VerificationException('Payment verification was not successful.', $response->status());
        }

        return $response->json();
    }
}
