<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantContextMissingException extends HttpException
{
    public function __construct(
        string $message = 'Active clinic context is required to perform this action.',
        ?\Throwable $previous = null,
        int $code = 0,
        array $headers = []
    ) {
        parent::__construct(403, $message, $previous, $headers, $code);
    }
}
