<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentScheduleRequest;
use App\Http\Requests\UpdateAgentScheduleRequest;
use App\Http\Resources\AgentScheduleResource;
use App\Jobs\RunScheduledAgent;
use App\Models\Agent;
use App\Models\AgentSchedule;
use App\Scheduling\AgentScheduleCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class AgentScheduleController extends Controller
{
    public function index(Agent $agent): JsonResponse
    {
        $schedules = AgentSchedule::query()
            ->where('agent_id', $agent->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => AgentScheduleResource::collection($schedules),
        ]);
    }

    public function store(
        StoreAgentScheduleRequest $request,
        Agent $agent,
        AgentScheduleCalculator $calculator,
    ): JsonResponse {
        $validated = $request->validated();

        $schedule = new AgentSchedule([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'name' => (string) $validated['name'],
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'deliver_to_channel' => (bool) ($validated['deliver_to_channel'] ?? false),
            'timezone' => filled($validated['timezone'] ?? null)
                ? (string) $validated['timezone']
                : null,
            'schedule_type' => (string) $validated['schedule_type'],
            'schedule_config' => $validated['schedule_config'],
            'message' => (string) $validated['message'],
            'context_id' => $validated['context_id'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        $schedule->next_run_at = $schedule->enabled
            ? $calculator->nextRunAt($schedule)
            : null;

        $schedule->save();

        return response()->json([
            'data' => new AgentScheduleResource($schedule),
        ], 201);
    }

    public function show(AgentSchedule $agentSchedule): JsonResponse
    {
        return response()->json([
            'data' => new AgentScheduleResource($agentSchedule),
        ]);
    }

    public function update(
        UpdateAgentScheduleRequest $request,
        AgentSchedule $agentSchedule,
        AgentScheduleCalculator $calculator,
    ): JsonResponse {
        $validated = $request->validated();
        $scheduleFieldsChanged = false;

        if (array_key_exists('name', $validated)) {
            $agentSchedule->name = (string) $validated['name'];
        }

        if (array_key_exists('enabled', $validated)) {
            $agentSchedule->enabled = (bool) $validated['enabled'];
            $scheduleFieldsChanged = true;
        }

        if (array_key_exists('deliver_to_channel', $validated)) {
            $agentSchedule->deliver_to_channel = (bool) $validated['deliver_to_channel'];
        }

        if (array_key_exists('timezone', $validated)) {
            $agentSchedule->timezone = filled($validated['timezone'] ?? null)
                ? (string) $validated['timezone']
                : null;
            $scheduleFieldsChanged = true;
        }

        if (array_key_exists('schedule_type', $validated)) {
            $agentSchedule->schedule_type = (string) $validated['schedule_type'];
            $scheduleFieldsChanged = true;
        }

        if (array_key_exists('schedule_config', $validated)) {
            $agentSchedule->schedule_config = $validated['schedule_config'];
            $scheduleFieldsChanged = true;
        }

        if (array_key_exists('message', $validated)) {
            $agentSchedule->message = (string) $validated['message'];
        }

        if (array_key_exists('context_id', $validated)) {
            $agentSchedule->context_id = $validated['context_id'];
        }

        if (array_key_exists('metadata', $validated)) {
            $agentSchedule->metadata = $validated['metadata'];
        }

        if ($scheduleFieldsChanged || $agentSchedule->next_run_at === null) {
            $agentSchedule->next_run_at = $agentSchedule->enabled
                ? $calculator->nextRunAt($agentSchedule)
                : null;
        }

        $agentSchedule->save();

        return response()->json([
            'data' => new AgentScheduleResource($agentSchedule->refresh()),
        ]);
    }

    public function runNow(AgentSchedule $agentSchedule): JsonResponse
    {
        if (! $agentSchedule->enabled) {
            return response()->json([
                'message' => 'Enable the schedule before running it manually.',
            ], 409);
        }

        $scheduledFor = now()->toIso8601String();

        RunScheduledAgent::dispatch(
            agentScheduleId: $agentSchedule->id,
            scheduledFor: $scheduledFor,
            recalculateNextRun: false,
            dispatchFingerprint: $agentSchedule->dispatchFingerprint(),
        );

        return response()->json([
            'data' => [
                'scheduled_for' => $scheduledFor,
            ],
        ], 202);
    }

    public function destroy(AgentSchedule $agentSchedule): Response
    {
        $agentSchedule->delete();

        return response()->noContent();
    }
}
