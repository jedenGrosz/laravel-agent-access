<?php

declare(strict_types=1);
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';

$base = $argv[1] ?? 'http://127.0.0.1:8073';
$client = new Client(['base_uri' => $base, 'http_errors' => false, 'timeout' => 15]);
$tokens = [];
foreach ([1, 2] as $id) {
    $process = new Process([PHP_BINARY, 'vendor/bin/testbench', 'demo:token', (string) $id], dirname(__DIR__, 2));
    $process->mustRun();
    $tokens[$id] = trim($process->getOutput());
}
$http = function (string $method, string $path, int $user, ?array $body = null) use ($client, $tokens): array {
    $options = ['headers' => ['Authorization' => 'Bearer '.$tokens[$user], 'Accept' => 'application/json']];
    if ($body !== null) {
        $options['json'] = $body;
    }
    $response = $client->request($method, $path, $options);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Unexpected HTTP status '.$response->getStatusCode().' for '.$path);
    }

    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$args = ['order_id' => 431, 'amount_minor' => 10000, 'currency' => 'PLN'];
$input = ['action' => 'orders.refund', 'arguments' => $args];
$check = $http('POST', '/agent-access/v1/check', 1, $input);
if ($check['code'] !== 'ACCESS_REQUIRED') {
    throw new RuntimeException('Reset the demo before running the smoke test.');
}

$rpc = function (string $name, int $user, array $arguments) use ($client, $tokens): array {
    $response = $client->post('/mcp', ['headers' => ['Authorization' => 'Bearer '.$tokens[$user], 'Accept' => 'application/json',
        'Mcp-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name],
        'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments,
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []]]]]);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Unexpected MCP HTTP status '.$response->getStatusCode());
    }
    $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($body['result']['structuredContent'])) {
        throw new RuntimeException('MCP did not return structured content: '.json_encode($body));
    }

    return $body['result'];
};
$denial = $rpc('refund_order', 1, $args);
if (! $denial['isError'] || $denial['structuredContent']['code'] !== 'ACCESS_REQUIRED') {
    throw new RuntimeException('MCP denial failed.');
}
$request = $rpc('access.request', 1, $input)['structuredContent'];
$approved = $http('POST', '/agent-access/v1/requests/'.$request['id'].'/approve', 2, []);
$success = $rpc('refund_order', 1, $args);
if ($success['isError'] || ! $success['structuredContent']['refunded']) {
    throw new RuntimeException('MCP execution failed.');
}
$again = $rpc('refund_order', 1, $args);
if (! $again['isError']) {
    throw new RuntimeException('Second refund was not denied.');
}
echo "Live HTTP/MCP: real Sanctum authentication, structured denial, request, cross-transport approval, refund and second-use denial passed.\n";
