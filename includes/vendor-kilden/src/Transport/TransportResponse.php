<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\Transport;

final class TransportResponse
{
    /** @var int */
    private $status;

    /** @var string */
    private $body;

    /** @var array<string, string> lower-cased header name => value */
    private $headers;

    /** @var bool */
    private $networkError;

    /** @var string */
    private $errorMessage;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, string $body, array $headers = [], bool $networkError = false, string $errorMessage = '')
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
        $this->networkError = $networkError;
        $this->errorMessage = $errorMessage;
    }

    public static function failure(string $message): self
    {
        return new self(0, '', [], true, $message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);

        return isset($this->headers[$key]) ? $this->headers[$key] : null;
    }

    public function isNetworkError(): bool
    {
        return $this->networkError;
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }
}
