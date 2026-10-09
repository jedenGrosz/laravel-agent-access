# Local V0.1 release candidate

V0.1 preparation stays local. The package name is jedengrosz/laravel-agent-access. Source is tracked on GitHub; Packagist publication remains a separate distribution step.

## Reproduce the checks

```sh
composer install
composer validate --strict
composer check
docker compose up -d postgres mysql
docker compose build test
docker compose run --rm test php vendor/bin/phpunit
docker compose run --rm -e LAA_DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 test php vendor/bin/phpunit
```

The SQLite suite skips the five independent-process race tests. The database runs require all tests, including idempotent request creation, simultaneous approval, one-use execution, approve/cancel and revoke/admission races. Test containers use their own databases. The GitHub workflow repeats PHP 8.3/8.4/8.5 with Laravel 12/13 and both real databases when run in a remote repository.

Test a new host with runtime dependencies only; optional integrations must not be installed solely to boot the core. Use a Composer path repository pointing at this checkout, require `jedengrosz/laravel-agent-access:@dev`, publish, configure, validate and migrate. The independent smoke scenario is available as:

```sh
php tests/Support/fresh-consumer-smoke.php /absolute/path/to/isolated/laravel-app
```

This fixture creates demonstration tables/users in the named host. Run it only in a disposable new application. It requires the host's stock `App\Models\User`, SQLite and the core package; it checks that MCP/Spatie are absent. Repeat the installation to verify migration publication remains idempotent.

For the authenticated transport smoke test, prepare a disposable Workbench demo and start it in a second terminal:

```sh
composer demo:prepare
php vendor/bin/testbench serve --host=127.0.0.1 --port=8073
```

Then run:

```sh
php tests/Support/live-demo-smoke.php http://127.0.0.1:8073
```

The smoke test issues short-lived user tokens internally, checks HTTP, submits a request over MCP, approves over HTTP, executes over MCP and rejects a second attempt. It does not print tokens. Reset the demo before another run; its refund is a real local database effect.

## Build a reviewable source archive

After committing verified changes, create an ignored output directory and archive that exact Git revision:

```sh
git archive --format=zip --prefix=laravel-agent-access/ --output=/absolute/output/path/laravel-agent-access-0.1.0-rc.1.zip HEAD
```

`git archive` includes tracked package source, migrations, documentation, Workbench, tests and CI. Vendor directories, tokens, local databases, caches and test results are ignored and not included. Record the commit ID and SHA-256 checksum beside the archive. This is a local source candidate, not a Packagist release or a Composer version declaration.

## Boundaries for V0.1

- No own OAuth/RBAC server, autonomous-agent identities, GUI or multi-person approval.
- No automatic action resumption or retry after approval/failure.
- No exactly-once guarantee for external effects; host handlers implement idempotency.
- One permission and one optional resource per action; scalar argument equality only.
- Visibility filtering evaluates PHP policies while scanning candidates; large hosts may need query optimization.
- Passport integration uses existing host authentication. A separate disposable host verifies OAuth discovery, consent, PKCE and real-token HTTP/MCP execution.

See [verification evidence](verification.md) for the local compatibility and integration checks. Public distribution uses jedengrosz/laravel-agent-access after reviewing the source candidate.
