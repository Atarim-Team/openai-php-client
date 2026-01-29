<?php

declare(strict_types=1);

namespace OpenAI\Exceptions;

use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

final class TransporterException extends Exception
{
    private readonly ?ResponseInterface $response;

    private readonly ?string $responseBody;

    public function __construct(
        ClientExceptionInterface $exception,
        ?ResponseInterface $response = null,
        ?string $responseBody = null
    ) {
        $this->response = $response;
        $this->responseBody = $responseBody;

        $message = $exception->getMessage();

        if ($responseBody !== null && $responseBody !== '') {
            $message .= ' | Response: ' . $responseBody;
        }

        parent::__construct($message, $response?->getStatusCode() ?? 0, $exception);
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->response?->getStatusCode() ?? 0;
    }
}
