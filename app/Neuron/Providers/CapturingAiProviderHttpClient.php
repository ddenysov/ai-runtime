<?php

namespace App\Neuron\Providers;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

class CapturingAiProviderHttpClient implements HttpClientInterface
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $lastResponse = null;

    private HttpClientInterface $inner;

    public function __construct(?HttpClientInterface $inner = null)
    {
        $this->inner = $inner ?? new GuzzleHttpClient();
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $this->lastResponse = null;

        $response = $this->inner->request($request);
        $this->lastResponse = [
            'uri' => $request->uri,
            'status' => $response->statusCode,
            'headers' => $response->headers,
            'body' => $response->body,
            'json' => $response->json(),
        ];

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $this->lastResponse = [
            'uri' => $request->uri,
            'status' => null,
            'headers' => [],
            'body' => '',
        ];

        return new CapturingAiProviderStream(
            $this->inner->stream($request),
            fn (string $chunk): string => $this->appendStreamChunk($chunk),
        );
    }

    public function withBaseUri(string $baseUri): HttpClientInterface
    {
        $this->inner = $this->inner->withBaseUri($baseUri);

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): HttpClientInterface
    {
        $this->inner = $this->inner->withHeaders($headers);

        return $this;
    }

    public function withTimeout(float $timeout): HttpClientInterface
    {
        $this->inner = $this->inner->withTimeout($timeout);

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastResponse(): ?array
    {
        return $this->lastResponse;
    }

    private function appendStreamChunk(string $chunk): string
    {
        if ($this->lastResponse === null) {
            $this->lastResponse = [
                'uri' => null,
                'status' => null,
                'headers' => [],
                'body' => '',
            ];
        }

        $this->lastResponse['body'] .= $chunk;

        return $chunk;
    }
}
