<?php

use App\Application\DemoAi\ProcessDemoAi;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Infrastructure\Email\BoundedDemoMailbox;
use App\Infrastructure\Email\DeadlineAwareImapProtocol;
use App\Infrastructure\Email\DemoEmailNormalizer;
use App\Models\DemoAiRun;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$connection = config('database.connections.'.config('database.default'));
if (! $app->environment('e2e') || ! in_array($connection['driver'], ['mysql', 'mariadb'], true)
    || ! str_ends_with($connection['database'], '_test') || ! in_array($connection['host'], ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Browser fixture refuses any nonlocal, non-disposable database.');
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === 'seed-foreign') {
    echo DemoAiRun::factory()->create(['status' => 'failed', 'finalized_at' => now(), 'expires_at' => now()->addDays(7)])->id;

    return;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === 'ledger-counts') {
    echo json_encode([
        'runs' => DemoAiRun::query()->count(),
        'attempts' => DB::table('demo_ai_attempts')->count(),
        'jobs' => DB::table('jobs')->count(),
    ], JSON_THROW_ON_ERROR);

    return;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/build/') || in_array($path, ['/favicon.ico', '/robots.txt'], true)) {
    return false;
}

config([
    'demo_ai.enabled' => getenv('FOTP_BROWSER_LIVE') === 'true',
    'demo_ai.scheduler_enabled' => false,
    'demo_ai.counting_enabled' => true,
    'demo_ai.counting_policy_evidence_sha256' => hash('sha256', 'offline browser policy fixture'),
    'demo_ai.counting_policy_mode' => 'verified_zero_charge', 'demo_ai.counting_risk_acceptance_reference' => null,
    'demo_ai.api_key' => 'offline-browser-key',
    'demo_ai.mailbox_key' => 'authored-demo', 'demo_ai.mailbox_host' => 'imap.example.invalid',
    'demo_ai.mailbox_username' => 'offline-demo', 'demo_ai.mailbox_password' => 'offline',
    'demo_ai.mailbox_folder' => 'FOTP Demo', 'mail.default' => 'array',
]);
Http::preventStrayRequests();
Http::fake([
    'https://api.openai.com/v1/responses/input_tokens' => Http::response(['object' => 'response.input_tokens', 'input_tokens' => 1000]),
    'https://api.openai.com/v1/responses' => Http::response([
        'model' => 'gpt-6-luna', 'service_tier' => 'default', 'status' => 'completed',
        'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 1000], 'output_tokens' => 100],
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
            'source_id' => 'DEMO-001', 'recommendation' => 'APPLY',
            'reasons' => [['criterion_id' => 'scope_fit', 'explanation' => 'Bounded manual testing. <img src="https://tracking.invalid/pixel" onerror="steal()"><script>steal()</script> '.str_repeat('x', 100),
                'evidence' => [['field' => 'input.description', 'quote' => 'Manual testing only; no automation required.']]]],
            'missing_information' => [],
        ], JSON_THROW_ON_ERROR)]]]],
    ]),
]);
Sleep::fake(syncWithCarbon: true);
$raw = file_get_contents(__DIR__.'/../Fixtures/Emails/demo/001.eml');
$protocol = Mockery::mock(DeadlineAwareImapProtocol::class);
if (! $protocol instanceof ImapProtocol) {
    throw new LogicException('Controlled protocol must implement the installed IMAP transport.');
}
$protocol->shouldReceive('disableDebug', 'disableUidCache', 'useDeadline', 'reset')->andReturnNull();
$protocol->shouldReceive('setConnectionTimeout')->andReturnSelf();
$protocol->shouldReceive('connect')->andReturnTrue();
$protocol->shouldReceive('login')->andReturn(Response::empty()->setResult(true));
$protocol->shouldReceive('examineFolder')->andReturn(Response::empty()->setResult(['uidvalidity' => 1, 'uidnext' => 2]));
$protocol->shouldReceive('fetch')->andReturnUsing(function (array $fields, array $uids) use ($raw): Response {
    $values = ['UID' => $uids[0], 'RFC822.SIZE' => strlen($raw)];
    if (in_array('BODY.PEEK[]', $fields, true)) {
        $values['BODY[]'] = $raw;
    }

    return Response::empty()->setResult([$uids[0] => $values]);
});
$app->instance(BoundedDemoMailbox::class, new BoundedDemoMailbox(
    app(DemoEmailNormalizer::class), app(MonotonicClock::class), fn (): ImapProtocol => $protocol,
));

$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

if (getenv('FOTP_BROWSER_LIVE') === 'true' && preg_match('~^/demo-ai/runs(?:/[^/]+)?$~D', $path) === 1
    && $response->isSuccessful()) {
    $run = DemoAiRun::query()->whereIn('status', ['queued', 'running'])->orderBy('admitted_at')->first();
    if ($run !== null) {
        app(ProcessDemoAi::class)->execute($run->id);
    }
}
