<?php

namespace App\Neuron\Providers\Gemini;

use App\Neuron\Providers\CapturingAiProviderHttpClient;
use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

class GeminiThoughtSignatureProvider implements AIProviderInterface
{
    public function __construct(
        private readonly AIProviderInterface $inner,
        private readonly CapturingAiProviderHttpClient $httpCapture,
    ) {}

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->inner->systemPrompt($prompt);

        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        $this->inner->setTools($tools);

        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->inner->messageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->inner->toolPayloadMapper();
    }

    public function chat(Message ...$messages): Message
    {
        return $this->withThoughtSignature($this->inner->chat(...$messages));
    }

    public function stream(Message ...$messages): Generator
    {
        $generator = $this->inner->stream(...$messages);

        while ($generator->valid()) {
            yield $generator->current();
            $generator->next();
        }

        return $this->withThoughtSignature($generator->getReturn());
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        return $this->inner->structured($messages, $class, $response_schema);
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        $this->inner->setHttpClient($client);

        return $this;
    }

    private function withThoughtSignature(Message $message): Message
    {
        if (! $message instanceof ToolCallMessage || $message->getMetadata('thought_signature') !== null) {
            return $message;
        }

        $signature = $this->extractThoughtSignature($this->httpCapture->lastResponse());

        if ($signature !== null) {
            $message->addMetadata('thought_signature', $signature);
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    private function extractThoughtSignature(?array $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $json = $response['json'] ?? null;

        if (! is_array($json) && isset($response['body']) && is_string($response['body'])) {
            $decoded = json_decode($response['body'], true);
            $json = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($json)) {
            return null;
        }

        return $this->findThoughtSignature($json);
    }

    /**
     * @param  mixed  $value
     */
    private function findThoughtSignature(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $signature = $value['thoughtSignature'] ?? $value['thought_signature'] ?? null;

        if (is_string($signature) && $signature !== '') {
            return $signature;
        }

        foreach ($value as $item) {
            $signature = $this->findThoughtSignature($item);

            if ($signature !== null) {
                return $signature;
            }
        }

        return null;
    }
}
