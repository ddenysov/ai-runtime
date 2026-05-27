<?php

namespace App\Neuron\Providers;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

class EchoProvider implements AIProviderInterface
{
    private ?string $systemPrompt = null;

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new class implements MessageMapperInterface
        {
            public function map(array $messages): array
            {
                return $messages;
            }
        };
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class implements ToolMapperInterface
        {
            public function map(array $tools): array
            {
                return $tools;
            }
        };
    }

    public function chat(Message ...$messages): Message
    {
        $input = collect($messages)
            ->map(fn (Message $message): ?string => $message->getContent())
            ->filter()
            ->implode("\n");

        $prefix = $this->systemPrompt ? 'Echo runtime response' : 'Echo response';

        return new AssistantMessage("{$prefix}: {$input}");
    }

    public function stream(Message ...$messages): Generator
    {
        $message = $this->chat(...$messages);

        if (false) {
            yield new TextChunk('echo', '');
        }

        return $message;
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $messages = is_array($messages) ? $messages : [$messages];

        return $this->chat(...$messages);
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }
}
