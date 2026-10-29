<?php

/*
 * One racer: a separate PHP process with its own MySQL connection, sending one
 * real request through the application's HTTP kernel, middleware and all.
 *
 *   php tests/Concurrency/worker.php <spec.json> <barrier-dir> <worker-id>
 *
 * It boots, proves its connection is the isolated test database (the guard;
 * exit 3 otherwise), signs the spec's user in with the spec's session values,
 * then announces it is ready and waits at the barrier for the parent's go
 * signal before sending the request. It prints one `RACE-RESULT {json}` line:
 * its connection's identity, the response status, and any session errors.
 */

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyDatabase;
use Tests\Concurrency\Support\UnsafeDatabaseConnection;

require __DIR__.'/environment.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $specFile, $barrier, $workerId] = $argv;
$spec = json_decode((string) file_get_contents($specFile), true, flags: JSON_THROW_ON_ERROR);
$report = static fn (array $values) => print 'RACE-RESULT '.json_encode(['worker' => (int) $workerId, ...$values])."\n";

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

try {
    ConcurrencyDatabase::assertIsolated();
} catch (UnsafeDatabaseConnection $e) {
    $report(['refused' => $e->getMessage()]);
    exit(3);
}
$connection = (array) DB::selectOne('SELECT CONNECTION_ID() AS id, DATABASE() AS db, CURRENT_USER() AS account');

// The signed-in user and their clinic session, in the array session store the
// request's own StartSession middleware will read.
$user = User::query()->findOrFail($spec['user_id']);
$session = $app['session']->driver();
$session->setId(Str::random(40));
$session->start();
foreach ($spec['session'] as $key => $value) {
    $session->put($key, $value);
}
// SessionGuard's own key for the signed-in user (`SessionGuard::getName()`), built
// without resolving the guard, which needs a request this process has not bound yet.
$session->put('login_web_'.sha1(SessionGuard::class), $user->getAuthIdentifier());
$session->save();

$cookie = config('session.cookie');
$encrypter = $app['encrypter'];
$request = Request::create(
    $spec['uri'],
    $spec['method'],
    $spec['data'] ?? [],
    [$cookie => $encrypter->encrypt(CookieValuePrefix::create($cookie, $encrypter->getKey()).$session->getId(), false)],
    [],
    ['HTTP_ACCEPT' => ($spec['json'] ?? false) ? 'application/json' : 'text/html'],
);

// The barrier: ready, then nothing until the parent says go.
touch("{$barrier}/ready-{$workerId}");
$deadline = microtime(true) + 90;
while (! is_file("{$barrier}/go")) {
    if (microtime(true) > $deadline) {
        $report(['error' => 'no go signal']);
        exit(4);
    }
    usleep(200);
}

$response = $kernel->handle($request);
$errors = $app['session.store']->get('errors');
$report([
    'connection' => $connection,
    'status' => $response->getStatusCode(),
    'errors' => $errors ? $errors->getBag('default')->toArray() : [],
    'success' => $app['session.store']->get('success'),
]);
$kernel->terminate($request, $response);
