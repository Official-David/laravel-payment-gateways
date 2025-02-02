<?php

namespace Stephenjude\PaymentGateway\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Stephenjude\PaymentGateway\DataObjects\PaymentTransactionData;
use Stephenjude\PaymentGateway\DataObjects\SessionData;
use Stephenjude\PaymentGateway\Exceptions\InitializationException;
use Stephenjude\PaymentGateway\Exceptions\VerificationException;

class SeerbitProvider extends AbstractProvider
{
    public string $provider = 'seerbit';

    public function getToken(): PendingRequest
    {
        return Http::withToken($this->getToken())->acceptJson();
    }

    public function initializeTransaction(array $parameters = []): SessionData
    {
        $parameters['reference'] = 'SEBT_'.Str::random(12);

        $parameters['expires'] = config('payment-gateways.cache.session.expires');

        $parameters['session_cache_key'] = config('payment-gateways.cache.session.key').$parameters['reference'];

        $seerbit = $this->initializeProvider([
            'publicKey' => $this->publicKey,
            'email' => Arr::get($parameters, 'email'),
            'amount' => Arr::get($parameters, 'amount'),
            'currency' => Arr::get($parameters, 'currency'),
            'country' => Arr::get($parameters, 'country_code', 'NG'),
            'paymentReference' => Arr::get($parameters, 'reference'),
            'callbackUrl' => $parameters['callback_url'] ?? route(config('payment-gateways.routes.callback.name'), [
                'reference' => $parameters['reference'],
                'provider' => $this->provider,
            ]),
        ]);

        $sessionData = new SessionData(
            provider: $this->provider,
            sessionReference: $parameters['reference'],
            paymentReference: null,
            checkoutSecret: null,
            checkoutUrl: $seerbit['payments']['redirectLink'],
            expires: $parameters['expires'],
            closure: $parameters['closure'] ? new SerializableClosure($parameters['closure']) : null,
        );

        return Cache::remember(
            key: $parameters['session_cache_key'],
            ttl: $parameters['expires'],
            callback: fn () => $sessionData
        );
    }

    public function confirmTransaction(string $reference, ?SerializableClosure $closure): PaymentTransactionData|null
    {
        $monnify = $this->verifyTransaction($reference);

        $paymentData = new PaymentTransactionData(
            email: $monnify['customers']['customerEmail'],
            meta: [
                'sourceIP' => $monnify['payments']['sourceIP'],
                'deviceType' => $monnify['payments']['deviceType'],
            ],
            amount: $monnify['payments']['amount'],
            currency: $monnify['payments']['currency'],
            reference: $reference,
            provider: $this->provider,
            status: $monnify['payments']['gatewayMessage'],
            date: Carbon::parse($monnify['payments']['transactionProcessedTime'])->toDateTimeString(),
        );

        $this->executeClosure($closure, $paymentData);

        return $paymentData;
    }

    public function initializeProvider(array $parameters): mixed
    {
        try {
            return $this->getToken()
                ->post($this->baseUrl.'api/v2/payments', $parameters)
                ->json('data');
        } catch (\Exception $exception) {
            throw new InitializationException($exception->getMessage(), $exception->getCode());
        }
    }

    public function verifyTransaction(string $reference): mixed
    {
        try {
            return $this->getToken()
                ->get($this->baseUrl."api/v2/payments/query/$reference")
                ->json('data');
        } catch (\Exception $exception) {
            throw new VerificationException($exception->getMessage(), $exception->getCode());
        }
    }

    private function getToken()
    {
        return Http::acceptJson()
            ->post($this->baseUrl.'api/v2/encrypt/keys', ['key' => $this->secretKey.'.'.$this->publicKey])
            ->json('data.EncryptedSecKey.encryptedKey');
    }
}
