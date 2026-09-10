<?php

declare(strict_types=1);

namespace OpenAI\Transporters;

use Closure;
use GuzzleHttp\Exception\ClientException;
use JsonException;
use OpenAI\Contracts\TransporterContract;
use OpenAI\Enums\Transporter\ContentType;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;
use OpenAI\ValueObjects\Transporter\AdaptableResponse;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;
use OpenAI\ValueObjects\Transporter\Payload;
use OpenAI\ValueObjects\Transporter\QueryParams;
use OpenAI\ValueObjects\Transporter\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class HttpTransporter implements TransporterContract
{
    /**
     * Creates a new Http Transporter instance.
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly BaseUri $baseUri,
        private Headers $headers,
        private readonly QueryParams $queryParams,
        private readonly Closure $streamHandler,
    ) {
        // ..
    }

    /**
     * {@inheritDoc}
     */
    public function addHeader(string $name, string $value): self
    {
        $this->headers = $this->headers->withCustomHeader($name, $value);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function requestObject(Payload $payload): Response
    {
        $request = $payload->toRequest($this->baseUri, $this->headers, $this->queryParams);

        $response = $this->sendRequest(fn (): ResponseInterface => $this->client->sendRequest($request));

        $contents = (string) $response->getBody();

        $this->throwIfRateLimit($response);
        $this->throwIfServerError($response);
        $this->throwIfJsonError($response, $contents);

        try {
            /** @var array{error?: array{message: string, type: string, code: string}} $data */
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new UnserializableResponse($jsonException, $response);
        }

        return Response::from($data, $response->getHeaders());
    }

    /**
     * {@inheritDoc}
     */
    public function requestStringOrObject(Payload $payload): AdaptableResponse
    {
        $request = $payload->toRequest($this->baseUri, $this->headers, $this->queryParams);

        $response = $this->sendRequest(fn (): ResponseInterface => $this->client->sendRequest($request));

        $contents = (string) $response->getBody();

        $this->throwIfRateLimit($response);
        $this->throwIfServerError($response);
        $this->throwIfJsonError($response, $contents);

        if (str_contains($response->getHeaderLine('Content-Type'), ContentType::TEXT_PLAIN->value)) {
            return AdaptableResponse::from($contents, $response->getHeaders());
        }

        try {
            /** @var array{error?: array{message: string, type: string, code: string}} $data */
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new UnserializableResponse($jsonException, $response);
        }

        return AdaptableResponse::from($data, $response->getHeaders());
    }

    /**
     * {@inheritDoc}
     */
    public function requestContent(Payload $payload): string
    {
        $request = $payload->toRequest($this->baseUri, $this->headers, $this->queryParams);

        $response = $this->sendRequest(fn (): ResponseInterface => $this->client->sendRequest($request));

        $contents = (string) $response->getBody();

        $this->throwIfRateLimit($response);
        $this->throwIfServerError($response);
        $this->throwIfJsonError($response, $contents);

        return $contents;
    }

    /**
     * {@inheritDoc}
     */
    public function requestStream(Payload $payload): ResponseInterface
    {
        $request = $payload->toRequest($this->baseUri, $this->headers, $this->queryParams);

        $response = $this->sendRequest(fn () => ($this->streamHandler)($request));

        $this->throwIfRateLimit($response);
        $this->throwIfServerError($response);
        $this->throwIfJsonError($response, $response);

        return $response;
    }

    private function sendRequest(Closure $callable): ResponseInterface
    {
        try {
            return $callable();
        } catch (ClientExceptionInterface $clientException) {
            $response = null;
            $responseBody = null;

            if ($clientException instanceof ClientException) {
                $response = $clientException->getResponse();
                $responseBody = (string) $response->getBody();
                $this->throwIfJsonError($response, $responseBody);
            }

            throw new TransporterException($clientException, $response, $responseBody);
        }
    }

    private function throwIfRateLimit(ResponseInterface $response): void
    {
        if ($response->getStatusCode() !== 429) {
            return;
        }

        throw new RateLimitException($response);
    }

    private function throwIfServerError(ResponseInterface $response): void
    {
        if ($response->getStatusCode() < 500) {
            return;
        }

        throw new ServerException($response);
    }

    private function throwIfJsonError(ResponseInterface $response, string|ResponseInterface $contents): void
    {
        if ($response->getStatusCode() < 400) {
            return;
        }

        if ($contents instanceof ResponseInterface) {
            $contents = (string) $contents->getBody();
        }

        try {
            /** @var array{error?: string|array{message: string|array<int, string>, type: string, code: string}}|array<int, array{error?: string|array{message: string|array<int, string>, type: string, code: string}}> $data */
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            if (isset($data['error']['metadata']['raw'])) {
                throw new ErrorException($this->contentsFromProviderRaw($data['error']), $response, $contents);
            }
            if (isset($data['error'])) {
                throw new ErrorException($data['error'], $response, $contents);
            }

            if (isset($data[0]['error'])) {
                throw new ErrorException($data[0]['error'], $response);
            }
            throw new ErrorException($data, $response, $contents);
        } catch (JsonException $jsonException) {
            // Due to some JSON coming back from OpenAI as text/plain, we need to avoid an early return from purely content-type checks.
            if (! str_contains($response->getHeaderLine('Content-Type'), ContentType::JSON->value)) {
                return;
            }

            throw new UnserializableResponse($jsonException, $response);
        }
    }

    /**
     * OpenRouter forwards the upstream provider's own error body as a string in
     * `error.metadata.raw`.
     *
     * It is not reliably JSON — an HTML error page, plain text or a truncated
     * body arrive just as often — and json_decode() then returns null, which
     * ErrorException's string|array parameter rejects with a TypeError. That
     * replaced a clean, non-retryable provider error with a type error the
     * caller cannot classify, so the request was retried until its deadline.
     *
     * When it is JSON it is usually the provider's whole `{"error": {...}}`
     * envelope rather than the error itself, which left the message with no
     * `message` or `code` to read and degraded it to the entire raw body.
     *
     * Falls back to OpenRouter's own error object, which is always well formed.
     *
     * @param  array<string, mixed>  $error
     * @return array<string, mixed>|string
     */
    private function contentsFromProviderRaw(array $error): array|string
    {
        $metadata = $error['metadata'] ?? null;
        $raw = is_array($metadata) ? ($metadata['raw'] ?? null) : null;

        if (! is_string($raw) || $raw === '') {
            return $error;
        }

        $decoded = json_decode($raw, true);

        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        if (! is_array($decoded)) {
            return $raw;
        }

        $inner = $decoded['error'] ?? null;

        return is_array($inner) || is_string($inner) ? $inner : $decoded;
    }
}
