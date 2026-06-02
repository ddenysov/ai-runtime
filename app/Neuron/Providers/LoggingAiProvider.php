<?php

namespace App\Neuron\Providers;

use Generator;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use Throwable;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

class LoggingAiProvider implements AIProviderInterface
{
    public function __construct(
        private readonly AIProviderInterface $inner,
        private readonly ?CapturingAiProviderHttpClient $httpCapture = null,
    ) {}

    public function inner(): AIProviderInterface
    {
        return $this->inner;
    }

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
        try {
            $response = $this->inner->chat(...$messages);
            $this->logResponse('chat', $response);

            return $response;
        } catch (Throwable $exception) {
            $this->logError('chat', $exception);

            throw $exception;
        }
    }

    public function stream(Message ...$messages): Generator
    {
        try {
            $generator = $this->inner->stream(...$messages);

            while ($generator->valid()) {
                yield $generator->current();
                $generator->next();
            }

            $response = $generator->getReturn();
            $this->logResponse('stream', $response);

            return $response;
        } catch (Throwable $exception) {
            $this->logError('stream', $exception);

            throw $exception;
        }
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        try {
            $response = $this->inner->structured($messages, $class, $response_schema);
            $this->logResponse('structured', $response, [
                'structured_class' => $class,
            ]);

            return $response;
        } catch (Throwable $exception) {
            $this->logError('structured', $exception, [
                'structured_class' => $class,
            ]);

            throw $exception;
        }
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        $this->inner->setHttpClient($client);

        return $this;
    }

    private function logResponse(string $method, Message $response, array $context = []): void
    {
        $content = $response->getContent();
        $payload = [
            'provider' => $this->inner::class,
            'method' => $method,
            'content' => $content,
            'response' => $response->jsonSerialize(),
            ...$context,
        ];

        $stopReason = $this->stopReason($response);

        if ($stopReason !== null) {
            $payload['stop_reason'] = $stopReason;
        }

        if ($content === null && ! $response instanceof ToolCallMessage) {
            Log::channel('llm')->error('LLM response did not contain message content.', [
                ...$payload,
                'provider_raw_response' => $this->httpCapture?->lastResponse(),
            ]);
        }

        Log::channel('llm')->info('LLM response received.', $payload);
    }

    private function logError(string $method, Throwable $exception, array $context = []): void
    {
        $payload = [
            'provider' => $this->inner::class,
            'method' => $method,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            ...$context,
        ];

        if ($exception instanceof HttpException) {
            $payload['http_uri'] = $exception->request?->uri;
            $payload['http_status'] = $exception->response?->statusCode;
            $payload['http_response'] = $exception->response?->body;
        }

        if ($this->httpCapture?->lastResponse() !== null) {
            $payload['provider_raw_response'] = $this->httpCapture->lastResponse();
        }

        Log::channel('llm')->error('LLM request failed.', $payload);
    }

    private function stopReason(Message $response): ?string
    {
        $stopReason = $response->getMetadata('stop_reason');

        return is_string($stopReason) && $stopReason !== '' ? $stopReason : null;
    }
}
