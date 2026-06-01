<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateAgentInstructionsRequest;
use App\Models\Agent;
use App\Services\AgentInstructionsGenerator;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class AgentInstructionsGeneratorController extends Controller
{
    public function store(
        GenerateAgentInstructionsRequest $request,
        Agent $agent,
        AgentInstructionsGenerator $generator,
    ): JsonResponse {
        try {
            $result = $generator->generate(
                target: $agent,
                brief: $request->validated('brief'),
                feedback: $request->validated('feedback'),
                draftInstructions: $request->validated('draft_instructions'),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }
}
