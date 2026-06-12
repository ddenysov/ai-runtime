<?php

namespace App\Neuron\Tools;

use App\Neuron\Diary\DiaryService;
use App\Neuron\Tools\Concerns\ReturnsJsonToolResponses;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

class DiaryReadTool extends Tool
{
    use ReturnsJsonToolResponses;

    public function __construct(
        private readonly DiaryService $diary,
    ) {
        parent::__construct(
            name: 'diary_read',
            description: 'Read diary entries for today or a specific date in YYYY-MM-DD format.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'date',
                type: PropertyType::STRING,
                description: 'Optional diary date in YYYY-MM-DD format. Defaults to today.',
                required: false,
            ),
        ];
    }

    public function __invoke(?string $date = null): string
    {
        try {
            return $this->success($this->diary->read($date));
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }
}
