<?php

declare(strict_types=1);
use LaravelAgentAccess\Actions\ActionExecutor;
use LaravelAgentAccess\Approvals\ApprovalService;
use LaravelAgentAccess\Exceptions\AccessException;
use LaravelAgentAccess\Grants\GrantService;
use LaravelAgentAccess\Identity\ExecutionContext;
use LaravelAgentAccess\Identity\PrincipalDirectory;
use LaravelAgentAccess\Requests\AccessRequestService;
use Tests\Support\WorkerHarness;

require __DIR__.'/../../vendor/autoload.php';

$harness = new WorkerHarness('worker');
$app = $harness->createApplication();
$context = new ExecutionContext(app(PrincipalDirectory::class)->find($argv[3]));
$barrier = $argv[4];
file_put_contents($barrier.'/'.$argv[5].'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timed out.');
    }
    usleep(1000);
}
try {
    $result = match ($argv[1]) {
        'approve' => app(ApprovalService::class)->approve($context, $argv[2])->id,
        'cancel' => app(AccessRequestService::class)->cancel($context, $argv[2])->status,
        'revoke' => app(GrantService::class)->revoke($context, $argv[2])->id,
        'execute' => app(ActionExecutor::class)->execute($context, 'orders.refund', ['order_id' => 431, 'amount_minor' => 10000, 'currency' => 'PLN']),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (AccessException $e) {
    echo json_encode(['ok' => false, 'code' => $e->reasonCode], JSON_THROW_ON_ERROR);
}
