<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Models\Approval;
use LaravelAgentAccess\Models\Grant;
use LaravelAgentAccess\Models\GrantUse;
use LaravelAgentAccess\Requests\AccessRequestService;
use Symfony\Component\Process\Process;

final class ConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (! in_array(getenv('LAA_DB_CONNECTION'), ['pgsql', 'mysql'], true)) {
            self::markTestSkipped('Run with PostgreSQL/MySQL; SQLite cannot prove row locking.');
        }
        parent::setUp();
    }

    /**
     * @param list<array{string, string, int}> $operations
     * @return list<array<string, mixed>> */
    private function race(array $operations, string $table, string $id): array
    {
        $barrier = sys_get_temp_dir().'/laa-'.Str::ulid();
        mkdir($barrier);
        DB::beginTransaction();
        DB::table($table)->where('id', $id)->lockForUpdate()->first();
        $workers = [];
        try {
            foreach ($operations as $index => [$operation, $target, $actor]) {
                $worker = new Process([PHP_BINARY, __DIR__.'/Support/concurrency-worker.php', $operation, $target, (string) $actor, $barrier, (string) $index], dirname(__DIR__), timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            foreach ($workers as $index => $worker) {
                while (! is_file($barrier.'/'.$index.'.ready')) {
                    if (! $worker->isRunning() || microtime(true) > $deadline) {
                        self::fail('Worker could not start: '.$worker->getErrorOutput().$worker->getOutput());
                    }
                    usleep(10000);
                }
            }
            file_put_contents($barrier.'/go', 'go');
            usleep(200000); // Both independent connections contend while the parent owns the row lock.
            foreach ($workers as $worker) {
                self::assertTrue($worker->isRunning());
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($barrier.'/*') ?: [] as $file) {
                unlink($file);
            } rmdir($barrier);
        }
    }

    public function test_two_approvals_create_one_grant(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $results = $this->race([['approve', $request->id, $this->manager->id], ['approve', $request->id, $this->manager->id]], 'agent_access_requests', $request->id);
        self::assertTrue($results[0]['ok']);
        self::assertTrue($results[1]['ok']);
        self::assertSame($results[0]['result'], $results[1]['result']);
        self::assertSame(1, Grant::query()->count());
        self::assertSame(1, Approval::query()->count());
    }

    public function test_two_uses_run_one_handler(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $grant = app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
        $results = $this->race([['execute', $grant->id, $this->support->id], ['execute', $grant->id, $this->support->id]], 'agent_access_grants', $grant->id);
        self::assertSame(1, count(array_filter($results, fn ($result) => $result['ok'])));
        self::assertSame(1, $grant->fresh()->uses);
        self::assertSame(1, GrantUse::query()->count());
        self::assertSame(1, DB::table('refunds')->count());
    }

    public function test_approve_and_cancel_have_one_terminal_winner(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $results = $this->race([['approve', $request->id, $this->manager->id], ['cancel', $request->id, $this->support->id]], 'agent_access_requests', $request->id);
        self::assertSame(1, count(array_filter($results, fn ($result) => $result['ok'])));
        $status = $request->fresh()->status;
        self::assertContains($status, ['approved', 'cancelled']);
        self::assertSame($status === 'approved' ? 1 : 0, Grant::query()->count());
    }

    public function test_revoke_and_execute_respect_admission_order(): void
    {
        $request = app(AccessRequestService::class)->create($this->context(), 'orders.refund', $this->args());
        $grant = app(ApprovalService::class)->approve($this->context($this->manager), $request->id);
        $results = $this->race([['revoke', $grant->id, $this->support->id], ['execute', $grant->id, $this->support->id]], 'agent_access_grants', $grant->id);
        self::assertTrue($results[0]['ok']);
        self::assertNotNull($grant->fresh()->revoked_at);
        self::assertSame($results[1]['ok'] ? 1 : 0, DB::table('refunds')->count());
        self::assertSame($results[1]['ok'] ? 1 : 0, $grant->fresh()->uses);
    }
}
