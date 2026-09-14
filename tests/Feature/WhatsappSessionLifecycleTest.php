<?php

use App\Jobs\SendGuardianWhatsappJob;
use App\Jobs\SendWhatsappTasksJob;
use App\Livewire\Manager\WhatsappSettings;
use App\Models\Manager;
use App\Support\WhatsappGateway;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.whatsapp.url' => 'http://localhost:3000',
        'services.whatsapp.key' => 'k',
        // No human-like pause while testing; it only slows the suite down.
        'services.whatsapp.send_delay_min' => 0,
        'services.whatsapp.send_delay_max' => 0,
    ]);
});

it('reads a stopped session without asking for one to be started', function () {
    Http::fake(['*/status/*' => Http::response(['status' => 'stopped', 'message' => 'الجلسة متوقفة.'])]);

    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(WhatsappSettings::class)->assertSet('status', 'stopped');

    // Merely looking must not start a browser.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/connect/'));
});

it('starts a session only when the manager asks', function () {
    Http::fake([
        '*/status/*' => Http::response(['status' => 'stopped']),
        '*/connect/*' => Http::response(['success' => true, 'status' => 'starting']),
    ]);

    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(WhatsappSettings::class)
        ->call('connect')
        ->assertSet('status', 'starting');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/connect/') && $request->method() === 'POST');
});

it('waits for a woken session rather than giving up on it', function () {
    $statuses = ['stopped', 'starting', 'ready'];

    Http::fake([
        '*/status/*' => function () use (&$statuses) {
            return Http::response(['status' => array_shift($statuses) ?? 'ready']);
        },
        '*/connect/*' => Http::response(['success' => true]),
    ]);

    expect(WhatsappGateway::waitUntilReady('supervisor_1', 30, pollSeconds: 0))->toBeTrue();

    // It had to start the session itself, having found nothing running.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/connect/'));
});

it('gives up on a session that is waiting to be scanned', function () {
    Http::fake(['*/status/*' => Http::response(['status' => 'needs_scan'])]);

    // Nobody is going to scan a QR code on behalf of a queue worker.
    expect(WhatsappGateway::waitUntilReady('supervisor_1', 30, pollSeconds: 0))->toBeFalse();
});

/**
 * release() delegates to the underlying queue job, so that is what to watch.
 */
function queueJobExpecting(?int $delay, int $attempts = 1)
{
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempts);

    if ($delay === null) {
        $queueJob->shouldNotReceive('release');
    } else {
        $queueJob->shouldReceive('release')->once()->with($delay);
    }

    return $queueJob;
}

it('releases a guardian message instead of dropping it while the session warms', function () {
    Http::fake(['*/send' => Http::response(['success' => false, 'retryable' => true], 503)]);

    $job = new SendGuardianWhatsappJob('0500000000', 'مرحباً', 'supervisor_1');
    $job->setJob(queueJobExpecting(30));

    $job->handle();
});

it('backs off further with each attempt', function () {
    Http::fake(['*/send' => Http::response(['success' => false, 'retryable' => true], 503)]);

    $job = new SendGuardianWhatsappJob('0500000000', 'مرحباً', 'supervisor_1');
    $job->setJob(queueJobExpecting(120, attempts: 3));

    $job->handle();
});

it('logs and stops retrying a refusal that will not fix itself', function () {
    Http::fake(['*/send' => Http::response(['success' => false, 'message' => 'bad number'], 500)]);

    $job = new SendGuardianWhatsappJob('0500000000', 'مرحباً', 'supervisor_1');
    $job->setJob(queueJobExpecting(null));

    $job->handle();
});

it('does not retry a message that was delivered', function () {
    Http::fake(['*/send' => Http::response(['success' => true])]);

    $job = new SendGuardianWhatsappJob('0500000000', 'مرحباً', 'supervisor_1');
    $job->setJob(queueJobExpecting(null));

    $job->handle();
});

it('defers a whole broadcast before sending anyone, not part way through', function () {
    Http::fake(['*/status/*' => Http::response(['status' => 'needs_scan'])]);

    $job = new SendWhatsappTasksJob([], 'supervisor_1');
    $job->setJob(queueJobExpecting(120));

    $job->handle();

    // Nothing was delivered, so a later retry cannot duplicate anything.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send'));
});
