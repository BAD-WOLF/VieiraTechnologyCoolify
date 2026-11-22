<?php

namespace App\Services\Platform\Efi;

use Illuminate\Http\Client\Response;

/**
 * Error returned by the Efí APIs (message safe to show to the operator).
 */
class EfiException extends \RuntimeException
{
    public ?int $status = null;

    /** @var array<string, mixed> */
    public array $body = [];

    public static function fromResponse(string $context, Response $response): self
    {
        $body = (array) $response->json();
        $parts = array_filter([
            data_get($body, 'title') ?? data_get($body, 'nome'),
            data_get($body, 'detail') ?? data_get($body, 'mensagem') ?? (is_string(data_get($body, 'message')) ? data_get($body, 'message') : null),
            is_string(data_get($body, 'error_description')) ? data_get($body, 'error_description') : (is_string(data_get($body, 'error')) ? data_get($body, 'error') : null),
            // Cobranças API: {"error": "validation_error", "error_description": {"property": "/payment/...", "message": "..."}}
            is_array(data_get($body, 'error_description')) ? trim(data_get($body, 'error_description.property', '').': '.data_get($body, 'error_description.message', ''), ': ') : null,
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
