<?php

namespace App\Neuron\Tools;

use App\Neuron\Diary\DiaryService;
use App\Neuron\Tools\Concerns\ReturnsJsonToolResponses;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

class DiaryWriteTool extends Tool
{
    use ReturnsJsonToolResponses;

    public function __construct(
        private readonly DiaryService $diary,
    ) {
        parent::__construct(
            name: 'diary_write',
            description: 'Append a diary entry for today. The tool chooses the date, file name, and timestamp automatically.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'text',
                type: PropertyType::STRING,
                description: 'Diary entry text in markdown-friendly plain language.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $text): string
    {
        try {
            return $this->success($this->diary->write($text));
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }
}
