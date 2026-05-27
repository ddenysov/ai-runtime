<?php

namespace App\A2A\Http;

use App\A2A\AgentCardFactory;
use Illuminate\Http\JsonResponse;

class AgentCardController
{
    public function __invoke(string $agent, AgentCardFactory $cards): JsonResponse
    {
        return response()->json($cards->make($agent));
    }
}
