<?php

namespace App\Mcp\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{name: string, title: ?string, description: ?string, input_schema: array<string, mixed>} $resource
 */
class McpServerToolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'],
            'title' => $this->resource['title'],
            'description' => $this->resource['description'],
            'input_schema' => $this->resource['input_schema'],
        ];
    }
}
