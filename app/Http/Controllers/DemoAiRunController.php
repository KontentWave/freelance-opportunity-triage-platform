<?php

namespace App\Http\Controllers;

use App\Application\DemoAi\DemoAiConfiguration;
use App\Application\DemoAi\DemoAiLedger;
use App\Application\DemoAi\DemoAiReport;
use App\Domain\DemoAi\ApprovedDemoInputs;
use App\Domain\DemoAi\DemoAiException;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class DemoAiRunController extends Controller
{
    public function __construct(private readonly DemoAiConfiguration $configuration) {}

    public function index(Request $request): View
    {
        $this->context($request, false);

        return view('review.ai');
    }

    public function create(Request $request, DemoAiLedger $ledger, ApprovedDemoInputs $approved): JsonResponse
    {
        [$workspace, $session] = $this->context($request, false);
        $key = $request->session()->get('demo_ai_intent');
        if ($request->isMethod('post') || (! is_string($key) && config('demo_ai.enabled'))) {
            abort_unless(config('demo_ai.enabled'), 404);
            if (is_string($key)) {
                $current = $ledger->existing($workspace, $session, $key, $request->session()->get('demo_ai_intent_version'));
                abort_if($current !== null && in_array($current->status, ['queued', 'running'], true), 409);
            }
            $key = Str::random(48);
            $keys = array_filter((array) $request->session()->get('demo_ai_keys', []), fn (int $until): bool => $until > time());
            $keys[$key] = time() + 1800;
            $request->session()->put('demo_ai_keys', array_slice($keys, -180, null, true));
            $request->session()->put('demo_ai_intent', $key);
            $request->session()->put('demo_ai_intent_version', $approved->versionHash());
            $versions = array_intersect_key((array) $request->session()->get('demo_ai_key_versions', []), $keys);
            $versions[$key] = $approved->versionHash();
            $request->session()->put('demo_ai_key_versions', $versions);
        }
        $existing = is_string($key)
            ? $ledger->existing($workspace, $session, $key, $request->session()->get('demo_ai_intent_version')) : null;

        return response()->json([
            'available' => (bool) config('demo_ai.enabled'), 'idempotency_key' => $key,
            'key_expires_at' => is_string($key) ? $request->session()->get('demo_ai_keys.'.$key) : null,
            'run' => $existing === null ? null : $this->summary($existing),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, DemoAiLedger $ledger, ApprovedDemoInputs $approved): JsonResponse
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
        $version = $request->session()->get('demo_ai_key_versions.'.$data['idempotency_key']);
        if (! is_string($version)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Request a current server-issued admission key.']);
        }
        $existing = $ledger->existing($workspace, $session, $data['idempotency_key'], $version);
        if ($existing !== null) {
            return response()->json($this->summary($existing), $existing->status === 'rejected' ? 429 : 202)->header('Cache-Control', 'no-store');
        }
        $until = $request->session()->get('demo_ai_keys.'.$data['idempotency_key']);
        if (! is_int($until) || $until <= time()) {
            throw ValidationException::withMessages(['idempotency_key' => 'Request a current server-issued admission key.']);
        }
        if ($version !== $approved->versionHash()) {
            throw ValidationException::withMessages(['idempotency_key' => 'The approved inputs changed. Deliberately request a new evaluation.']);
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
        $record = $this->owned($run, $workspace, $session);
        abort_if($this->expired($record), 410);
        if (! Cache::store('demo-ai')->add('demo-ai:status:'.$session.':'.$run, true, 2)) {
            return response()->json(['error_code' => 'demo_ai.poll_rate'], 429)->header('Retry-After', '2');
        }
        $items = DemoAiItem::query()->where('run_id', $run)->where('workspace_id', $workspace)->orderBy('ordinal')
            ->get(['source_id', 'status', 'result', 'failure_code']);

        return response()->json($this->summary($record) + [
            'label' => "Fictional demo alerts evaluated against Marcel's public profile",
            'items' => $items, 'profile_version' => $record->approved_snapshot['profile_version'] ?? null,
            'prompt_version' => $record->approved_snapshot['prompt_version'] ?? null,
        ])->header('Cache-Control', 'no-store');
    }

    public function report(Request $request, string $run): Response
    {
        [$workspace, $session] = $this->context($request, false);
        $record = $this->owned($run, $workspace, $session);
        if ($this->expired($record)) {
            return response('This fictional demo report has expired after seven days. Opening it does not restart evaluation.', 410)
                ->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'private, no-store');
        }
        abort_if($record->report_snapshot === null, 409);

        $response = response()->view('review.ai-report', ['report' => $record->report_snapshot])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        if ($request->routeIs('demo-ai.download')) {
            $response->header('Content-Disposition', 'attachment; filename="fictional-demo-'.$record->id.'.html"');
        }

        return $response;
    }

    private function owned(string $id, string $workspace, string $session): DemoAiRun
    {
        return DemoAiRun::query()->whereKey($id)->where('workspace_id', $workspace)
            ->where('session_hash', $session)->firstOrFail();
    }

    private function expired(DemoAiRun $run): bool
    {
        return $run->purged_at !== null || ($run->expires_at?->lessThanOrEqualTo(now()) ?? false);
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
            'explanation' => DemoAiReport::explanation($run->failure_code),
            'report_url' => $run->report_snapshot === null ? null : route('demo-ai.report', ['run' => $run->id], false),
            'download_url' => $run->report_snapshot === null ? null : route('demo-ai.download', ['run' => $run->id], false),
        ];
    }
}
