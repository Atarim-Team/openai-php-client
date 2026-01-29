<?php

declare(strict_types=1);

namespace OpenAI\Exceptions;

use Exception;
use Psr\Http\Message\ResponseInterface;

final class ErrorException extends Exception
{
    private readonly int $statusCode;

    private readonly ?string $rawResponseBody;

    /**
     * @param  array{message?: string|array<int, string>, type?: ?string, code?: string|int|null}|string  $contents
     */
    public function __construct(
        private readonly string|array $contents,
        public readonly ResponseInterface $response,
        ?string $rawResponseBody = null
    ) {
        $this->statusCode = $response->getStatusCode();
        $this->rawResponseBody = $rawResponseBody;

        $contents = is_string($contents) ? ['message' => $contents] : $contents;
        $message = ($contents['message'] ?? null) ?: (string) ($contents['code'] ?? null) ?: null;

        if ($message === null || $message === '') {
            $message = $rawResponseBody !== null
                ? 'API Error: ' . $rawResponseBody
                : 'Unknown error';
        }

        if (is_array($message)) {
            $message = implode(PHP_EOL, $message);
        }

        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorMessage(): string
    {
        return $this->getMessage();
    }

    public function getErrorType(): ?string
    {
        return $this->contents['type'] ?? null;
    }

    public function getErrorCode(): string|int|null
    {
        return $this->contents['code'] ?? null;
    }

    public function getRawResponseBody(): ?string
    {
        return $this->rawResponseBody;
    }
}
