<?php

namespace App\Mcp\Http\Requests;

use App\Shared\Delivery\Http\Requests\Concerns\RequiresExpectedVersion;
use Illuminate\Foundation\Http\FormRequest;

class DestroyMcpServerRequest extends FormRequest
{
    use RequiresExpectedVersion;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->expectedVersionRules();
    }
}
