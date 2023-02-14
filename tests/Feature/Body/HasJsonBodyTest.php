<?php

declare(strict_types=1);

use Saloon\Http\PendingRequest;
use Saloon\Http\Faking\MockResponse;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Promise\FulfilledPromise;
use Saloon\HttpSender\Tests\Fixtures\Connectors\HttpSenderConnector;
use Saloon\HttpSender\Tests\Fixtures\Requests\HasJsonBodyRequest;

test('the default body is loaded', function () {
    $request = new HasJsonBodyRequest();

    expect($request->body()->all())->toEqual([
        'name' => 'Sam',
        'catchphrase' => 'Yeehaw!',
    ]);
});

test('the content-type header is set in the pending request', function () {
    $request = new HasJsonBodyRequest();

    $pendingRequest = HttpSenderConnector::make()->createPendingRequest($request);

    expect($pendingRequest->headers()->all())->toEqual([
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ]);
});

test('the guzzle sender properly sends it', function () {
    $connector = new HttpSenderConnector;
    $request = new HasJsonBodyRequest;

    $request->middleware()->onRequest(static function (PendingRequest $pendingRequest) {
        expect($pendingRequest->headers()->get('Content-Type'))->toEqual('application/json');
    });

    $connector->sender()->addMiddleware(function (callable $handler) use ($request) {
        return function (RequestInterface $guzzleRequest, array $options) use ($request) {
            expect($guzzleRequest->getHeader('Content-Type'))->toEqual(['application/json']);
            expect((string)$guzzleRequest->getBody())->toEqual((string)$request->body());

            return new FulfilledPromise(MockResponse::make()->getPsrResponse());
        };
    });

    $connector->send($request);
});
