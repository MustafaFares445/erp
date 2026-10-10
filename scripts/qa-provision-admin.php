<?php

declare(strict_types=1);
use App\Models\User;
use Database\Seeders\Demo\DemoContext;
use Illuminate\Support\Facades\Hash;

// Runs only inside a validated QA worker; the credential stays in the worker's private runtime.
$argv = [__FILE__, getcwd(), (string) getenv('DB_DATABASE')];
require __DIR__.'/qa-guard.php';

$secretPath = getcwd().'/tmp/qa-secrets/admin-password';
if (! is_file($secretPath)) {
    throw new RuntimeException('Worker administrator secret has not been generated.');
}
$password = mb_trim((string) file_get_contents($secretPath));
if (mb_strlen($password) < 40) {
    throw new RuntimeException('Worker administrator secret is invalid.');
}
$email = DemoContext::Actors['admin'];
$user = User::query()->where('email', $email)->firstOrFail();
$user->forceFill(['password' => Hash::make($password)])->save();
$user->refresh();
if (! Hash::check($password, (string) $user->password)) {
    throw new RuntimeException('Worker administrator credential verification failed.');
}
file_put_contents(getcwd().'/tmp/qa-secrets/admin-username', $email);
echo "QA_ADMIN_PROVISIONED\n";
