<?php

namespace App\Gate;

use Symfony\Component\HttpFoundation\Response;

final class Nginx404Response
{
    private const BODY = <<<'HTML'
<html>
<head><title>404 Not Found</title></head>
<body>
<center><h1>404 Not Found</h1></center>
<hr><center>nginx</center>
</body>
</html>

HTML;

    public static function body(): string
    {
        return self::BODY;
    }

    public static function toResponse(): Response
    {
        return new Response(
            self::BODY,
            Response::HTTP_NOT_FOUND,
            [
                'Server' => 'nginx',
                'Content-Type' => 'text/html',
                'Content-Length' => (string) strlen(self::BODY),
                'Connection' => 'close',
            ],
        );
    }

    public static function send(): never
    {
        if (! headers_sent()) {
            header('HTTP/1.1 404 Not Found');
            header('Server: nginx');
            header('Content-Type: text/html');
            header('Content-Length: '.strlen(self::BODY));
            header('Connection: close');
        }

        echo self::BODY;

        exit;
    }
}
