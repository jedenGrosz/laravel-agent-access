<?php

declare(strict_types=1);

use AgentAccessFixture\FixtureOrder;
use AgentAccessFixture\FixtureUser;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use LaravelAgentAccess\Models\AuditEvent;
use LaravelAgentAccess\Models\Grant;
use Symfony\Component\Process\Process;

try {
    // Destructive fixture setup: use a disposable Laravel host, never an existing application database.
    $consumer = realpath($argv[1] ?? __DIR__.'/../../.test-results/passport-consumer');
    if ($consumer === false || ! is_file($consumer.'/vendor/laravel/passport/composer.json')) {
        throw new RuntimeException('Install Passport and the package in a disposable consumer first.');
    }
    require $consumer.'/vendor/autoload.php';
    require __DIR__.'/PassportFixture.php';
    $database = $consumer.'/database/passport-fixture.sqlite';
    if (! is_file($database)) {
        touch($database);
    }
    $app = \AgentAccessFixture\application($consumer);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $app['migrator']->path(__DIR__.'/../../database/migrations');
    $app['migrator']->path($consumer.'/vendor/laravel/passport/database/migrations');
    if ($kernel->call('migrate:fresh', ['--force' => true]) !== 0) {
        throw new RuntimeException('Fixture migration failed.');
    }
    Schema::create('fixture_orders', function (Blueprint $table): void {
        $table->id();
        $table->boolean('refunded')->default(false);
    });
    FixtureUser::query()->create(['id' => 1, 'name' => 'support', 'email' => 'support@example.test', 'password' => 'unused']);
    FixtureUser::query()->create(['id' => 2, 'name' => 'finance_manager', 'email' => 'manager@example.test', 'password' => 'unused']);
    FixtureOrder::query()->create(['id' => 431]);
    if (! is_file($consumer.'/storage/oauth-private.key') && $kernel->call('passport:keys', ['--length' => 2048]) !== 0) {
        throw new RuntimeException('Could not create test keys.');
    }
    // Reserve an ephemeral loopback port; the child process receives only the fixture directory.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Could not reserve test port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://'.$address;
    $redirect = $base.'/callback';
    $oauthClient = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Local MCP PKCE fixture', [$redirect], confidential: false);
    $server = new Process([PHP_BINARY, ...(PHP_OS_FAMILY === 'Windows' ? ['-d', 'extension=sodium'] : []), '-S', $address, __DIR__.'/passport-router.php'], $consumer,
        ['LAA_PASSPORT_CONSUMER' => $consumer], timeout: 120);
    $server->start();
    $http = new Client(['base_uri' => $base, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 10]);
    $ensure = function (bool $condition, string $message): void {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            try {
                $ready = $http->get('/_fixture/health')->getStatusCode() === 200;
            } catch (ConnectException) {
            }
            if ($ready) {
                break;
            }
            if (! $server->isRunning()) {
                throw new RuntimeException('Fixture server failed to start.');
            }
            usleep(100000);
        }
        $ensure($ready, 'Fixture server was not ready.');
        $metadata = json_decode((string) $http->get('/.well-known/oauth-authorization-server')->getBody(), true);
        $ensure(in_array('S256', $metadata['code_challenge_methods_supported'] ?? [], true), 'OAuth discovery omitted PKCE.');
        $resource = json_decode((string) $http->get('/.well-known/oauth-protected-resource/mcp')->getBody(), true);
        $ensure($resource['resource'] === $base.'/mcp', 'Discovery resolved the wrong MCP resource.');
        $tokens = [];
        foreach ([1, 2] as $id) {
            $cookies = new CookieJar;
            $http->get('/_fixture/login/'.$id, ['cookies' => $cookies]);
            $verifier = bin2hex(random_bytes(32));
            $state = bin2hex(random_bytes(12));
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $consentResponse = $http->get('/oauth/authorize', ['cookies' => $cookies, 'query' => ['client_id' => $oauthClient->id,
                'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => $state,
                'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'prompt' => 'consent']]);
            $ensure($consentResponse->getStatusCode() === 200, 'OAuth consent page was not returned.');
            $consent = json_decode((string) $consentResponse->getBody(), true);
            $approved = $http->post('/oauth/authorize', ['cookies' => $cookies, 'form_params' => ['_token' => $consent['csrf_token'],
                'auth_token' => $consent['auth_token'], 'client_id' => $oauthClient->id, 'state' => $state]]);
            $ensure($approved->getStatusCode() === 302, 'Consent approval did not redirect.');
            parse_str(parse_url($approved->getHeaderLine('Location'), PHP_URL_QUERY), $callback);
            $ensure(($callback['state'] ?? null) === $state && isset($callback['code']), 'OAuth callback did not preserve state/code.');
            if ($id === 1) {
                $wrongVerifier = $http->post('/oauth/token', ['form_params' => ['grant_type' => 'authorization_code', 'client_id' => $oauthClient->id,
                    'redirect_uri' => $redirect, 'code' => $callback['code'], 'code_verifier' => str_repeat('x', 64)]]);
                $ensure($wrongVerifier->getStatusCode() >= 400 && $wrongVerifier->getStatusCode() < 500, 'Wrong PKCE verifier was accepted.');
            }
            $exchange = $http->post('/oauth/token', ['form_params' => ['grant_type' => 'authorization_code', 'client_id' => $oauthClient->id,
                'redirect_uri' => $redirect, 'code' => $callback['code'], 'code_verifier' => $verifier]]);
            $ensure($exchange->getStatusCode() === 200, 'PKCE token exchange failed.');
            $tokens[$id] = json_decode((string) $exchange->getBody(), true)['access_token'];
        }
        $args = ['order_id' => 431, 'amount_minor' => 10000, 'currency' => 'PLN'];
        $input = ['action' => 'orders.refund', 'arguments' => $args];
        $call = function (string $path, int $id, array $body) use ($http, $tokens) {
            return $http->post($path, ['headers' => ['Authorization' => 'Bearer '.$tokens[$id], 'Accept' => 'application/json'], 'json' => $body]);
        };
        $rpc = function (string $name, int $id, array $arguments) use ($http, $tokens, $ensure): array {
            $response = $http->post('/mcp', ['headers' => ['Authorization' => 'Bearer '.$tokens[$id], 'Accept' => 'application/json',
                'Mcp-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name],
                'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments,
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []]]]]);
            $ensure($response->getStatusCode() === 200, 'Authenticated MCP call failed.');

            return json_decode((string) $response->getBody(), true)['result'];
        };
        $ensure($http->post('/agent-access/v1/check', ['json' => $input, 'headers' => ['Accept' => 'application/json']])->getStatusCode() === 401, 'Anonymous request was accepted.');
        $denial = $rpc('refund_order', 1, $args);
        $ensure($denial['isError'] && $denial['structuredContent']['code'] === 'ACCESS_REQUIRED', 'Missing access was not explained.');
        $ensure($call('/agent-access/v1/requests', 1, $input + ['user_id' => 2])->getStatusCode() === 422, 'Token identity could be overridden.');
        $request = $rpc('access.request', 1, $input)['structuredContent'];
        $ensure($call('/agent-access/v1/requests/'.$request['id'].'/approve', 2, [])->getStatusCode() === 200, 'Passport manager approval failed.');
        $result = $rpc('refund_order', 1, $args);
        $ensure(! $result['isError'] && $result['structuredContent']['refunded'], 'Passport MCP refund failed.');
        $ensure($rpc('refund_order', 1, $args)['isError'], 'Second refund was accepted.');
        $ensure($call('/_fixture/revoke', 1, [])->getStatusCode() === 200, 'Token revocation failed.');
        $ensure($call('/agent-access/v1/check', 1, $input)->getStatusCode() === 401, 'Revoked Passport token remained usable.');
        $ensure(Grant::query()->first()->uses === 1 && FixtureOrder::query()->find(431)->refunded, 'Database effect/usage was incorrect.');
        $audit = json_encode(AuditEvent::query()->get()->toArray());
        foreach ($tokens as $token) {
            $ensure(! str_contains($audit, $token), 'Bearer token appeared in audit.');
        }
        echo "Passport OAuth: discovery, S256 PKCE, consent, real user tokens, HTTP/MCP approval/refund, identity rejection and token revocation passed.\n";
    } finally {
        $server->stop();
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Passport smoke failed: '.($error::class === RuntimeException::class ? $error->getMessage() : $error::class).PHP_EOL);
    exit(1);
}
