<?php

namespace Tests\Unit\Gate;

use App\Gate\GateRequestContext;
use App\Gate\GateVisitAlertMessage;
use Tests\TestCase;

class GateVisitAlertMessageTest extends TestCase
{
    public function test_includes_query_post_and_headers(): void
    {
        $context = GateRequestContext::fromServer([
            'REQUEST_URI' => '/login?foo=bar&baz=qux',
            'REQUEST_METHOD' => 'POST',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], [
            'email' => 'test@example.com',
            'password' => 'secret',
        ]);

        $message = GateVisitAlertMessage::fromContext($context);

        $this->assertStringContainsString('Method: POST', $message);
        $this->assertStringContainsString('Path: /login', $message);
        $this->assertStringContainsString('User-Agent: Mozilla/5.0', $message);
        $this->assertStringContainsString("Query:\n  foo=bar\n  baz=qux", $message);
        $this->assertStringContainsString("POST:\n  email=test@example.com\n  password=secret", $message);
        $this->assertStringContainsString('ACCEPT=text/html', $message);
        $this->assertStringContainsString('X-FORWARDED-FOR=203.0.113.1', $message);
        $this->assertStringContainsString('Content-Type=application/x-www-form-urlencoded', $message);
        $this->assertStringNotContainsString('USER-AGENT=', $message);
    }

    public function test_omits_empty_sections(): void
    {
        $context = GateRequestContext::fromServer([
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
            'HTTP_USER_AGENT' => 'curl/8.0',
        ]);

        $message = GateVisitAlertMessage::fromContext($context);

        $this->assertStringNotContainsString('Query:', $message);
        $this->assertStringNotContainsString('POST:', $message);
        $this->assertStringNotContainsString('Headers:', $message);
    }
}
