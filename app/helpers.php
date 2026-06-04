<?php

declare(strict_types=1);

if (!function_exists('now')) {
    /**
     * Returns the current UTC time as an ISO 8601 string.
     */
    function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

if (!function_exists('jsonError')) {
    /**
     * Build a standard error response body.
     */
    function jsonError(string $error, ?string $field = null): array
    {
        $body = ['error' => $error];
        if ($field !== null) {
            $body['field'] = $field;
        }
        return $body;
    }
}