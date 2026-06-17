<?php

namespace App\Gate;

final class GateVisitAlertMessage
{
    private const int MAX_LENGTH = 4000;

    public static function fromContext(GateRequestContext $request): string
    {
        $lines = [
            'Site access attempt',
            'Method: '.$request->method(),
            'Path: '.$request->path(),
            'User-Agent: '.$request->userAgent(),
        ];

        $querySection = self::formatSection('Query', $request->queryParams());

        if ($querySection !== '') {
            $lines[] = $querySection;
        }

        $postSection = self::formatSection('POST', $request->postParams());

        if ($postSection !== '') {
            $lines[] = $postSection;
        }

        $headersSection = self::formatSection('Headers', self::headersWithoutUserAgent($request->headers()));

        if ($headersSection !== '') {
            $lines[] = $headersSection;
        }

        $message = implode("\n", $lines);

        if (strlen($message) > self::MAX_LENGTH) {
            return substr($message, 0, self::MAX_LENGTH - 3).'...';
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function formatSection(string $label, array $params): string
    {
        if ($params === []) {
            return '';
        }

        $lines = [$label.':'];

        foreach ($params as $key => $value) {
            $lines[] = '  '.$key.'='.self::stringifyValue($value);
        }

        return implode("\n", $lines);
    }

    private static function stringifyValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function headersWithoutUserAgent(array $headers): array
    {
        unset($headers['User-Agent'], $headers['user-agent'], $headers['USER-AGENT']);

        return $headers;
    }
}
