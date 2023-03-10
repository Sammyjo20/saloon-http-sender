<?php

declare(strict_types=1);

namespace Saloon\HttpSender;

use Throwable;
use GuzzleHttp\RequestOptions;
use Saloon\Contracts\Response;
use Illuminate\Http\Client\Factory;
use Saloon\Contracts\PendingRequest;
use Saloon\Http\Senders\GuzzleSender;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Saloon\Repositories\Body\FormBodyRepository;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Repositories\Body\StringBodyRepository;
use Illuminate\Http\Client\Response as HttpResponse;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Repositories\Body\MultipartBodyRepository;
use Illuminate\Http\Client\RequestException as HttpRequestException;

class HttpSender extends GuzzleSender
{
    /**
     * Guzzle middleware used to handle Laravel's Pending Request.
     *
     * @var \Saloon\HttpSender\LaravelMiddleware
     */
    protected LaravelMiddleware $laravelMiddleware;

    /**
     * Constructor
     *
     * Create the HTTP client.
     */
    public function __construct()
    {
        parent::__construct();

        $this->handlerStack->push(
            $this->laravelMiddleware = new LaravelMiddleware
        );
    }

    /**
     * Send the request
     *
     * @param \Saloon\Contracts\PendingRequest $pendingRequest
     * @param bool $asynchronous
     * @return \Saloon\Contracts\Response|\GuzzleHttp\Promise\PromiseInterface
     * @throws \Exception
     */
    public function sendRequest(PendingRequest $pendingRequest, bool $asynchronous = false): Response|PromiseInterface
    {
        try {
            $laravelPendingRequest = $this->createLaravelPendingRequest($pendingRequest, $asynchronous);

            // We need to let Laravel catch and handle HTTP errors to preserve
            // the default behavior. It does so by inspecting the status code
            // instead of catching an exception which is what Saloon does.

            $pendingRequest->config()->merge([RequestOptions::HTTP_ERRORS => false]);

            // We should pass in the request options as there is a call inside
            // the send method that parses the HTTP options and the Laravel
            // data properly.

            /** @var \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface */
            $response = $laravelPendingRequest->send(
                $pendingRequest->getMethod()->value,
                $pendingRequest->getUrl(),
                $this->createRequestOptions($pendingRequest)
            );
        } catch (ConnectionException|ConnectException $exception) {
            throw new FatalRequestException($exception, $pendingRequest);
        }

        // When the response is a normal HTTP Client Response, we can create the response

        return $response instanceof HttpResponse
            ? $this->createResponse($pendingRequest, $response->toPsrResponse(), $response->toException())
            : $this->processPromise($response, $pendingRequest);
    }

    /**
     * Process the promise
     *
     * @param \GuzzleHttp\Promise\PromiseInterface $promise
     * @param \Saloon\Contracts\PendingRequest $pendingRequest
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function processPromise(PromiseInterface $promise, PendingRequest $pendingRequest): PromiseInterface
    {
        // When it comes to promises, it's a little tricky because of Laravel's built-in
        // exception handler which always converts a request exception into a response.
        // Here we will undo that functionality by catching the exception and throwing
        // it back down the "otherwise"/"catch" chain

        return $promise
            ->then(function (HttpResponse|TransferException $result) {
                $exception = $result instanceof TransferException ? $result : $result->toException();

                if ($exception instanceof Throwable) {
                    throw $exception;
                }

                return $result;
            })
            ->then(
                function (HttpResponse $response) use ($pendingRequest) {
                    return $this->createResponse($pendingRequest, $response->toPsrResponse());
                },
            )
            ->otherwise(
                function (HttpRequestException|TransferException $exception) use ($pendingRequest) {
                    // When the exception wasn't a HttpRequestException, we'll throw a fatal
                    // exception as this is likely a ConnectException, but it will
                    // catch any new ones Guzzle release.

                    if (! $exception instanceof HttpRequestException) {
                        throw new FatalRequestException($exception, $pendingRequest);
                    }

                    // Otherwise we'll create a response to convert into an exception.
                    // This will run the exception through the exception handlers
                    // which allows the user to handle their own exceptions.

                    $response = $this->createResponse($pendingRequest, $exception->response->toPsrResponse(), $exception);

                    // Throw the exception our way

                    throw $response->toException();
                }
            );
    }

    /**
     * Create the Laravel Pending Request
     *
     * @param PendingRequest $pendingRequest
     * @param bool $asynchronous
     * @return HttpPendingRequest
     */
    protected function createLaravelPendingRequest(PendingRequest $pendingRequest, bool $asynchronous = false): HttpPendingRequest
    {
        $httpPendingRequest = new HttpPendingRequest(resolve(Factory::class));
        $httpPendingRequest->setClient($this->client);

        $this->laravelMiddleware->setRequest($httpPendingRequest);

        if ($asynchronous === true) {
            $httpPendingRequest->async();
        }

        // Depending on the body format (if set) then we will specify the
        // body format on the pending request. This helps it determine
        // the Guzzle options to apply.

        $body = $pendingRequest->body();

        if (is_null($body)) {
            return $httpPendingRequest;
        }

        match (true) {
            $body instanceof JsonBodyRepository => $httpPendingRequest->bodyFormat('json'),
            $body instanceof MultipartBodyRepository => $httpPendingRequest->bodyFormat('multipart'),
            $body instanceof FormBodyRepository => $httpPendingRequest->bodyFormat('form_params'),
            $body instanceof StringBodyRepository => $httpPendingRequest->bodyFormat('body')->setPendingBody($body->all()),
            default => $httpPendingRequest->bodyFormat('body')->setPendingBody((string)$body),
        };

        return $httpPendingRequest;
    }
}
