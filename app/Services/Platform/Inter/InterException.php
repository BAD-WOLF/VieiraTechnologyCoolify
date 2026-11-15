<?php

namespace App\Services\Platform\Inter;

use Illuminate\Http\Client\Response;

/**
 * Error returned by the Banco Inter API (message safe to show to the operator).
 */
class InterException extends \RuntimeException
{
    public ?int $status = null;

    /** @var array<string, mixed> */
    public array $body = [];

    public static function fromResponse(string $context, Response $response): self
    {
        $body = (array) $response->json();
        $parts = array_filter([
            data_get($body, 'title'),
            data_get($body, 'detail'),
            data_get($body, 'error_description') ?? data_get($body, 'error'),
        ]);
        foreach ((array) data_get($body, 'violacoes', []) as $violation) {
            $parts[] = trim(data_get($violation, 'propriedade', '').': '.data_get($violation, 'razao', ''), ': ');
        }
        $message = $context.' failed (HTTP '.$response->status().')'.($parts ? ': '.implode(' | ', $parts) : '.');
        $exception = new self($message);
        $exception->status = $response->status();
        $exception->body = $body;

        return $exception;
    }
}
