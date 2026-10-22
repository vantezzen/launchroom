<?php

declare(strict_types=1);

use App\Actions\Audit\CreateAuditCheckpoint;
use App\Actions\Audit\CreateAuditSink;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Audit\TestAuditSink;
use App\Actions\Crypto\RotateKek;
use App\Contracts\DnsResolver;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Audit\AuditSinkInput;
use App\Enums\Audit\AuditSinkKind;
use App\Enums\Audit\AuditSinkStatus;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Jobs\Audit\VerifyAuditLogJob;
use App\Models\AuditEvent;
use App\Models\AuditSink;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditDeliveryFailed;
use App\Services\Audit\AuditForwarder;
use App\Services\Audit\AuditSinkSecrets;
use App\Services\Audit\Transports\SyslogConnector;
use App\Services\Audit\Transports\SyslogStream;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SecretKey;
use App\Services\Http\ResolvedTarget;
use App\Support\Alerts\WebhookSignature;
use App\Support\AuditChain;
use App\Support\S3\SigV4;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/*
| Audit log forwarding (docs/09 R34): syslog over TLS, signed webhooks and
| append-only S3 objects, per-sink cursor, retry with backoff, SSRF checks.
*/

final class RecordingSyslog implements SyslogConnector
{
    public string $received = '';

    /** @var list<array{host: string, ip: string, port: int, ca: string|null}> */
    public array $connections = [];

    public bool $fail = false;

    public function connect(ResolvedTarget $target, ?string $caPem, int $timeoutSeconds): SyslogStream
    {
        if ($this->fail) {
            throw AuditDeliveryFailed::because("Could not open a verified TLS connection to {$target->host}:{$target->port}.");
        }

        $this->connections[] = ['host' => $target->host, 'ip' => $target->ip, 'port' => $target->port, 'ca' => $caPem];

        return new readonly class($this) implements SyslogStream
        {
            public function __construct(private RecordingSyslog $sink) {}

            public function write(string $bytes): void
            {
                $this->sink->received .= $bytes;
            }

            public function close(): void {}
        };
    }
}

beforeEach(function (): void {
    app()->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $hostname): array
        {
            return match ($hostname) {
                'siem.example.com', 'logs.example.com', 's3.example.com' => ['93.184.216.34'],
                'internal.example.com' => ['10.0.0.5'],
                'loopback.example.com' => ['127.0.0.1'],
                default => [],
            };
        }
    });
    $this->syslog = new RecordingSyslog;
    app()->instance(SyslogConnector::class, $this->syslog);
});

function auditOwner(): User
{
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();

    return $owner;
}

/**
 * @param  array<string, mixed>  $fields
 */
function auditSink(AuditSinkKind $kind, array $fields = []): AuditSink
{
    $defaults = match ($kind) {
        AuditSinkKind::Webhook => ['secretUrl' => 'https://siem.example.com/launchroom'],
        AuditSinkKind::Syslog => ['host' => 'logs.example.com', 'port' => 6514],
        AuditSinkKind::S3 => ['endpoint' => 'https://s3.example.com', 'bucket' => 'audit-copies', 'region' => 'eu-central-1', 'prefix' => 'lr', 'accessKeyId' => 'AKIAAUDIT0001', 'secretAccessKey' => 'audit-secret-key'],
    };

    return resolve(CreateAuditSink::class)->handle(AuditSinkInput::from(['kind' => $kind, ...$defaults, ...$fields]), auditOwner(), AuditActor::system())->sink->refresh();
}

function moreAudit(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        resolve(RecordAuditEvent::class)->handle(new AuditEntryData(AuditAction::MemberInvited, AuditActor::system(), meta: ['email' => "u{$i}@example.com"]));
    }
}

test('a webhook gets every entry in order, signed, with the exact hashed fields and checkpoints', function (): void {
    Http::fake(['siem.example.com/*' => Http::response('', 204)]);
    moreAudit(2);
    resolve(CreateAuditCheckpoint::class)->handle();

    $created = resolve(CreateAuditSink::class)->handle(AuditSinkInput::from(['kind' => AuditSinkKind::Webhook, 'secretUrl' => 'https://siem.example.com/launchroom']), auditOwner(), AuditActor::system());
    $sink = $created->sink->refresh();
    $secret = (string) $created->signingSecret;

    expect($secret)->toStartWith('lrwh_')
        ->and($sink->cursor_event_id)->toBe((int) AuditEvent::query()->max('id'))
        ->and($sink->status())->toBe(AuditSinkStatus::Delivering);

    $records = [];
    Http::assertSent(function (Request $request) use ($secret, &$records): bool {
        expect(WebhookSignature::verify($secret, $request->header('X-Launchroom-Signature')[0] ?? '', $request->body(), time()))->toBeTrue();
        $body = json_decode($request->body(), true, 32, JSON_THROW_ON_ERROR);
        array_push($records, ...$body['records']);

        return $body['type'] === 'launchroom.audit.batch';
    });

    // An outside verifier rebuilds the chain from the records alone.
    $prev = AuditChain::GENESIS;
    $events = array_values(array_filter($records, static fn (array $r): bool => $r['type'] === 'event'));
    foreach ($events as $record) {
        expect($record['prevHash'])->toBe($prev)
            ->and(AuditChain::hash($prev, $record['fields']))->toBe($record['hash']);
        $prev = $record['hash'];
    }

    $checkpoint = collect($records)->firstWhere('type', 'checkpoint');
    $message = AuditChain::checkpointMessage($checkpoint['count'], CarbonImmutable::parse($checkpoint['createdAt']), $checkpoint['eventId'], $checkpoint['hash'], $checkpoint['instance']);
    $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(TestCase::signingSeed()));
    expect($events)->toHaveCount(3) // two entries + audit.sink_created
        ->and(sodium_crypto_sign_verify_detached((string) base64_decode($checkpoint['signature'], true), $message, $public))->toBeTrue();
});

test('new entries are forwarded right after they commit', function (): void {
    Http::fake(['siem.example.com/*' => Http::response('', 200)]);
    $sink = auditSink(AuditSinkKind::Webhook);
    Http::fake(['siem.example.com/*' => Http::response('', 200)]);

    moreAudit(1);

    expect($sink->refresh()->cursor_event_id)->toBe((int) AuditEvent::query()->max('id'));
});

test('a failing sink backs off, keeps its backlog and catches up in order', function (): void {
    $up = false;
    Http::fake(['siem.example.com/*' => function () use (&$up) {
        return $up ? Http::response('', 200) : Http::response('nope', 503);
    }]);
    $sink = auditSink(AuditSinkKind::Webhook);
    moreAudit(3);

    $sink->refresh();
    expect($sink->status())->toBe(AuditSinkStatus::Failing)
        ->and($sink->cursor_event_id)->toBe(0)
        ->and($sink->last_error)->toBe('The destination answered 503.')
        ->and($sink->next_attempt_at?->isFuture())->toBeTrue()
        ->and(AuditForwarder::backoffSeconds(1))->toBe(30)
        ->and(AuditForwarder::backoffSeconds(2))->toBe(60)
        ->and(AuditForwarder::backoffSeconds(20))->toBe(3600);

    // Still inside the backoff: the minute sweep waits.
    $up = true;
    $this->artisan('launchroom:audit:deliver')->assertSuccessful();
    expect($sink->refresh()->cursor_event_id)->toBe(0);

    $this->travel(2)->hours();
    $this->artisan('launchroom:audit:deliver')->assertSuccessful();

    $sink->refresh();
    expect($sink->cursor_event_id)->toBe((int) AuditEvent::query()->max('id'))
        ->and($sink->failures)->toBe(0)
        ->and($sink->last_error)->toBeNull();
});

test('syslog gets octet-counted RFC 5424 messages over TLS to the vetted address', function (): void {
    $sink = auditSink(AuditSinkKind::Syslog, ['caCertificate' => null]);
    moreAudit(1);

    $connection = $this->syslog->connections[0];
    expect($connection)->toMatchArray(['host' => 'logs.example.com', 'ip' => '93.184.216.34', 'port' => 6514])
        ->and($sink->refresh()->cursor_event_id)->toBe((int) AuditEvent::query()->max('id'));

    preg_match_all('/(\d+) (<110>1 \S+ \S+ launchroom - (audit|checkpoint) - \{)/', $this->syslog->received, $m, PREG_OFFSET_CAPTURE);
    expect($m[0])->not->toBeEmpty();

    // Every frame's length prefix is exact.
    $rest = $this->syslog->received;
    while ($rest !== '') {
        [$length, $tail] = explode(' ', $rest, 2);
        $frame = substr($tail, 0, (int) $length);
        expect($frame)->toStartWith('<110>1 ');
        $rest = substr($tail, (int) $length);
    }
});

test('syslog failures are recorded without the connection details leaking further', function (): void {
    $this->syslog->fail = true;
    $sink = auditSink(AuditSinkKind::Syslog);

    expect($sink->refresh()->status())->toBe(AuditSinkStatus::Failing)
        ->and($sink->last_error)->toContain('logs.example.com:6514');
});

test('S3 gets append-only JSON Lines objects with SigV4, and object lock headers when the bucket has it', function (): void {
    Http::fake(['s3.example.com/*' => fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), 'object-lock')
        ? Http::response('<ObjectLockConfiguration><ObjectLockEnabled>Enabled</ObjectLockEnabled></ObjectLockConfiguration>', 200)
        : Http::response('', 200)]);
    $sink = auditSink(AuditSinkKind::S3, ['retentionDays' => 30, 'lockMode' => 'compliance']);
    expect(resolve(TestAuditSink::class)->handle($sink))->toContain('has Object Lock');
    $sink->refresh();

    moreAudit(3);
    // S3 waits for a fuller object; force it.
    resolve(AuditForwarder::class)->deliver($sink->id, force: true);

    $puts = [];
    Http::assertSent(function (Request $request) use (&$puts): bool {
        if ($request->method() === 'PUT' && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '.jsonl')) {
            $puts[] = $request;
        }

        return true;
    });

    expect($puts)->not->toBeEmpty();
    $put = $puts[count($puts) - 1];
    $body = $put->body();
    expect(parse_url($put->url(), PHP_URL_PATH))->toMatch('#^/audit-copies/lr/launchroom-audit/ins_[0-9A-Z]{26}/\d{4}/\d{2}/\d{2}/\d{12}-\d{12}\.jsonl$#')
        ->and($put->header('If-None-Match')[0] ?? null)->toBe('*')
        ->and($put->header('x-amz-object-lock-mode')[0] ?? null)->toBe('COMPLIANCE')
        ->and($put->header('x-amz-object-lock-retain-until-date')[0] ?? null)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($put->header('x-amz-checksum-sha256')[0] ?? null)->toBe(base64_encode(hash('sha256', $body, true)))
        ->and($put->header('x-amz-content-sha256')[0] ?? null)->toBe(hash('sha256', $body))
        ->and($put->header('Authorization')[0] ?? '')->toMatch('#^AWS4-HMAC-SHA256 Credential=AKIAAUDIT0001/\d{8}/eu-central-1/s3/aws4_request, SignedHeaders=[a-z0-9;-]*if-none-match[a-z0-9;-]*x-amz-checksum-sha256[a-z0-9;-]*, Signature=[0-9a-f]{64}$#')
        ->and(collect(explode("\n", trim($body)))->map(fn (string $line): string => json_decode($line, true)['type'])->unique()->values()->all())->toContain('event')
        ->and($sink->refresh()->cursor_event_id)->toBe((int) AuditEvent::query()->max('id'));
});

test('SigV4 matches the AWS documentation example', function (): void {
    // https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html (GET Object example)
    $headers = SigV4::sign('GET', 'examplebucket.s3.amazonaws.com', '/test.txt', [], ['range' => 'bytes=0-9'],
        'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'us-east-1',
        'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', new DateTimeImmutable('2013-05-24T00:00:00Z'));

    expect($headers['Authorization'])->toBe('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41');
});

test('an already stored S3 batch (412) counts as delivered', function (): void {
    Http::fake(['s3.example.com/*' => Http::response('<Error><Code>PreconditionFailed</Code></Error>', 412)]);
    $sink = auditSink(AuditSinkKind::S3);
    resolve(AuditForwarder::class)->deliver($sink->id, force: true);

    expect($sink->refresh()->cursor_event_id)->toBe((int) AuditEvent::query()->max('id'));
});

test('destinations on private, loopback or unresolvable addresses are refused unless the owner allows the private network', function (): void {
    $owner = auditOwner();
    $post = fn (array $form) => $this->actingAs($owner)->post(route('audit-sinks.store'), $form);

    $post(['kind' => 'webhook', 'secretUrl' => 'https://internal.example.com/hook'])->assertSessionHasErrors('secretUrl');
    $post(['kind' => 'webhook', 'secretUrl' => 'http://siem.example.com/hook'])->assertSessionHasErrors('secretUrl');
    $post(['kind' => 'syslog', 'host' => 'loopback.example.com'])->assertSessionHasErrors('host');
    $post(['kind' => 's3', 'endpoint' => 'https://169.254.169.254', 'bucket' => 'x-bucket', 'accessKeyId' => 'a', 'secretAccessKey' => 'b'])->assertSessionHasErrors('endpoint');
    $post(['kind' => 'syslog', 'host' => 'loopback.example.com', 'allowPrivateNetwork' => true])->assertSessionHasErrors('host');
    expect(AuditSink::query()->count())->toBe(0);

    Http::fake();
    $post(['kind' => 'webhook', 'secretUrl' => 'https://internal.example.com/hook', 'allowPrivateNetwork' => true])->assertSessionHasNoErrors();
    $sink = AuditSink::query()->sole();
    expect($sink->allow_private_network)->toBeTrue();

    $event = AuditEvent::query()->where('action', AuditAction::AuditSinkCreated->value)->sole();
    expect($event->meta)->toMatchArray(['kind' => 'webhook', 'target' => 'internal.example.com', 'privateNetwork' => true]);
});

test('only instance owners see and manage destinations, with a recent password', function (): void {
    Http::fake();
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Owner);
    $form = ['kind' => 'webhook', 'secretUrl' => 'https://siem.example.com/hook'];

    $this->actingAs($admin)->get(route('instance-security.edit'))->assertForbidden();
    $this->actingAs($admin)->post(route('audit-sinks.store'), $form)->assertForbidden();

    $owner = auditOwner();
    $this->flushSession();
    $this->actingAs($owner)->post(route('audit-sinks.store'), $form)
        ->assertRedirect()->assertSessionHasNoErrors();
    $sink = AuditSink::query()->sole();

    $this->actingAs($admin)->patch(route('audit-sinks.update', $sink->public_id), ['enabled' => false])->assertForbidden();
    $this->actingAs($admin)->post(route('audit-sinks.test', $sink->public_id))->assertForbidden();
    $this->actingAs($admin)->delete(route('audit-sinks.destroy', $sink->public_id))->assertForbidden();

    $this->actingAs($owner)->patch(route('audit-sinks.update', $sink->public_id), ['enabled' => false])->assertRedirect();
    expect($sink->refresh()->status())->toBe(AuditSinkStatus::Paused);

    $this->actingAs($owner)->delete(route('audit-sinks.destroy', $sink->public_id))->assertRedirect();
    expect(AuditSink::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::AuditSinkDeleted->value)->count())->toBe(1);
});

test('credentials are envelope-encrypted, never serialized or rendered, and rewrapped with the KEK', function (): void {
    Http::fake();
    $owner = auditOwner();
    $this->actingAs($owner)->post(route('audit-sinks.store'), [
        'kind' => 's3', 'endpoint' => 'https://s3.example.com', 'bucket' => 'audit-copies',
        'accessKeyId' => 'AKIAVISIBLE', 'secretAccessKey' => 'super-secret-key-material',
    ])->assertSessionHasNoErrors();

    $sink = AuditSink::query()->sole();
    expect($sink->secret)->not->toContain('super-secret')
        ->and(json_encode($sink->toArray(), JSON_THROW_ON_ERROR))->not->toContain('lr1:')
        ->and(resolve(AuditSinkSecrets::class)->reveal($sink)['secretAccessKey'])->toBe('super-secret-key-material');

    $page = $this->actingAs($owner)->get(route('instance-security.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/instance-security')
            ->where('audit.sinks.0.kind', 's3')
            ->where('audit.sinks.0.target', 's3.example.com/audit-copies'));
    expect($page->getContent())->not->toContain('super-secret-key-material')->not->toContain('AKIAVISIBLE');

    $current = resolve(Envelope::class);
    $next = new Envelope(SecretKey::generate());
    resolve(RotateKek::class)->handle($current, $next);
    app()->instance(Envelope::class, $next);
    app()->forgetInstance(AuditSinkSecrets::class);
    expect($sink->refresh()->kek_id)->toBe($next->kekId());
    expect(resolve(AuditSinkSecrets::class)->reveal($sink->refresh())['accessKeyId'])->toBe('AKIAVISIBLE');
});

test('the activity page shows whether the audit log verified, without details for members', function (): void {
    moreAudit(1);
    $this->artisan('launchroom:audit:checkpoint');
    $this->artisan('launchroom:audit:verify');

    $team = Team::factory()->create();
    $member = memberOf($team, TeamRole::Developer);

    $this->actingAs($member)->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auditVerification.ok', true)
            ->where('auditVerification.problem', null)
            ->has('auditVerification.verifiedUpTo'));
});

test('"Verify now" runs a full check', function (): void {
    Queue::fake();
    $owner = auditOwner();

    $this->actingAs($owner)->post(route('audit-verification.store'))->assertRedirect();
    Queue::assertPushed(VerifyAuditLogJob::class);
});
