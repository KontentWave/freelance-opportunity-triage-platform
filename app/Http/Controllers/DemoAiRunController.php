<?php

namespace App\Http\Controllers;

use App\Application\DemoAi\DemoAiConfiguration;
use App\Application\DemoAi\DemoAiLedger;
use App\Domain\DemoAi\DemoAiException;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DemoAiRunController extends Controller
{
    public function __construct(private readonly DemoAiConfiguration $configuration) {}

    public function create(Request $request): JsonResponse
    {
        $this->context($request, true);
        $key = Str::random(48);
        $keys = array_filter((array) $request->session()->get('demo_ai_keys', []), fn (int $until): bool => $until > time());
        $keys[$key] = time() + 1800;
        $request->session()->put('demo_ai_keys', array_slice($keys, -180, null, true));

        return response()->json(['idempotency_key' => $key])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, DemoAiLedger $ledger): JsonResponse
    {
        [$workspace, $session] = $this->context($request, true);
        if (strlen($request->getContent()) > 1024) {
            abort(413);
        }
        $unsupported = array_diff(array_keys($request->all()), ['_token', 'idempotency_key']);
        if ($unsupported !== []) {
            throw ValidationException::withMessages([(string) reset($unsupported) => 'This field is not supported.']);
        }
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'size:48']]);
        $existing = $ledger->existing($workspace, $session, $data['idempotency_key']);
        if ($existing !== null) {
            return response()->json($this->summary($existing), $existing->status === 'rejected' ? 429 : 202)->header('Cache-Control', 'no-store');
        }
        $until = $request->session()->get('demo_ai_keys.'.$data['idempotency_key']);
        if (! is_int($until) || $until <= time()) {
            throw ValidationException::withMessages(['idempotency_key' => 'Request a current server-issued admission key.']);
        }
        try {
            $run = $ledger->admit($workspace, $session, hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), $data['idempotency_key']);
        } catch (DemoAiException $exception) {
            return response()->json(['error_code' => $exception->failureCode], 503);
        }

        return response()->json($this->summary($run), $run->status === 'rejected' ? 429 : 202)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $run): JsonResponse
    {
        [$workspace, $session] = $this->context($request, false);
        $record = DemoAiRun::query()->whereKey($run)->where('workspace_id', $workspace)
            ->where('session_hash', $session)->firstOrFail();
        if (! Cache::store('demo-ai')->add('demo-ai:status:'.$session.':'.$run, true, 2)) {
            return response()->json(['error_code' => 'demo_ai.poll_rate'], 429)->header('Retry-After', '2');
        }
        if ($record->purged_at !== null || $record->expires_at?->isPast()) {
            abort(410);
        }
        $items = DemoAiItem::query()->where('run_id', $run)->where('workspace_id', $workspace)->orderBy('ordinal')
            ->get(['source_id', 'status', 'result', 'failure_code']);

        return response()->json($this->summary($record) + [
            'label' => "Fictional demo alerts evaluated against Marcel's public profile",
            'items' => $items, 'profile_version' => 'public-profile-v2', 'prompt_version' => 'public-evaluation-v2',
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array{string, string} */
    private function context(Request $request, bool $admitting): array
    {
        abort_if(config('opportunity_review.mode') !== 'demo' || ($admitting && ! config('demo_ai.enabled')), 404);
        try {
            $workspace = $this->configuration->workspaceId();
        } catch (DemoAiException) {
            abort(404);
        }
        abort_if((string) $request->user()?->getAuthIdentifier() !== (string) config('opportunity_review.demo_user_id')
            || $request->user()?->workspace_id !== $workspace, 404);

        $owner = $request->session()->get('demo_ai_owner');
        if (! is_string($owner)) {
            $owner = Str::random(48);
            $request->session()->put('demo_ai_owner', $owner);
        }

        return [$workspace, hash_hmac('sha256', $owner, (string) config('app.key'))];
    }

    /** @return array<string, mixed> */
    private function summary(DemoAiRun $run): array
    {
        return [
            'id' => $run->id, 'status' => $run->status, 'selected_count' => $run->selected_count,
            'succeeded_count' => $run->succeeded_count, 'failed_count' => $run->failed_count,
            'failure_code' => $run->failure_code, 'admitted_at' => $run->admitted_at,
            'deadline_at' => $run->deadline_at, 'finalized_at' => $run->finalized_at, 'expires_at' => $run->expires_at,
        ];
    }
}
