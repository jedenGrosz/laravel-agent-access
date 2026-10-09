<?php

declare(strict_types=1);

namespace LaravelAgentAccess\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LaravelAgentAccess\Contracts\PrincipalResolver;

final readonly class ContextFactory
{
    public function __construct(private PrincipalResolver $principals) {}

    /**
     * @param array<string, mixed> $declared */
    public function authenticated(?Authenticatable $user, string $channel, array $declared = []): ExecutionContext
    {
        $principal = $this->principals->resolve($user);
        $trusted = [];
        $labels = [];
        $token = null;
        if (method_exists($principal->user, 'currentAccessToken')) {
            $token = $principal->user->currentAccessToken();
        } elseif (method_exists($principal->user, 'token')) {
            $token = $principal->user->token();
        }
        if ($token instanceof Model) {
            $id = $token->getKey();
            $client = $token->getAttribute('client_id');
            if (is_string($id) || is_int($id)) {
                $trusted['session_id'] = 'token:'.$id;
            }
            if (is_string($client) || is_int($client)) {
                $trusted['client_id'] = (string) $client;
            }
        }
        foreach (['client_id', 'session_id'] as $key) {
            if (isset($declared[$key]) && is_string($declared[$key])) {
                $labels[$key] = substr($declared[$key], 0, 128);
            }
        }

        return new ExecutionContext($principal, $channel, trustedMetadata: $trusted, declaredMetadata: $labels);
    }
}
