# Real Passport integration verification

The core does not require Passport. A separate disposable Laravel 13 host with Passport 13.9.0 and Laravel MCP 1.0.1 was used to verify existing-host integration locally. The GitHub workflow also defines this fixture job.

The smoke harness tests OAuth metadata discovery, a public authorization-code client, separate user sessions, an explicit consent response and S256 PKCE. A wrong verifier is rejected. Real user bearer tokens then exercise MCP denial and request, HTTP manager approval, MCP refund, second-use denial and token revocation. Anonymous requests and an attempt to override the token's user are rejected. Tokens never appear in package audit.

This tests the host's actual Passport controllers and authentication middleware over loopback HTTP. The fixture supplies its own small login/consent presentation; it does not claim to test the visual UI of an external OAuth client. Laravel Agent Access still does not create or operate a production OAuth server.

Create a disposable Laravel 13 host under `.test-results/passport-consumer`, configure a Composer path repository pointing to `../..`, and install the local package with `@dev`, `laravel/passport:^13.0` and `laravel/mcp:1.0.1`. The workflow contains the exact Linux setup commands.

```sh
php tests/Support/passport-smoke.php
# Or specify a disposable host with those packages:
php tests/Support/passport-smoke.php /absolute/path/to/disposable/host
```

Enable PHP sodium for Composer and the smoke process. On the tested Windows runtime, sodium is bundled and can be enabled for an invocation with `php -d extension=sodium`; run Composer's PHAR through that same PHP invocation when installing Passport dependencies.

The harness resets only its dedicated SQLite fixture database in the supplied disposable host, generates local test keys if absent, reserves an ephemeral loopback port and stops its child server on completion. Do not point it at an existing application. The test-only login route/provider are never registered by the package or copied into consumer application source.

The integration methods follow [Laravel Passport](https://laravel.com/docs/13.x/passport) and [Laravel MCP authentication](https://laravel.com/docs/13.x/mcp#authentication). The host continues to own OAuth clients, keys, scopes and consent in a real deployment.
