<?php

declare(strict_types=1);
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$consumer = getenv('LAA_PASSPORT_CONSUMER');
if (! is_string($consumer) || ! is_file($consumer.'/vendor/autoload.php')) {
    throw new RuntimeException('Missing disposable consumer.');
}
require $consumer.'/vendor/autoload.php';
require __DIR__.'/PassportFixture.php';
$app = \AgentAccessFixture\application($consumer);
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
