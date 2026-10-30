<?php
// Prepare a fresh copied application without needing database access.
@unlink(__DIR__.'/public/hot');
require __DIR__.'/vendor/autoload.php';
// Git does not keep empty folders, so a fresh clone lacks the runtime folders Laravel writes to.
foreach (['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $dir) {
    if (!is_dir(__DIR__.'/'.$dir) && !@mkdir(__DIR__.'/'.$dir, 0775, true) && !is_dir(__DIR__.'/'.$dir)) { fwrite(STDERR, "Cannot create $dir.\n"); exit(1); }
}
$path = __DIR__.'/.env';
if (!is_file($path) && !copy(__DIR__.'/.env.example', $path)) { fwrite(STDERR, "Cannot create .env.\n"); exit(1); }
$values = Dotenv\Dotenv::parse(file_get_contents($path));
if (empty($values['APP_KEY'])) {
    $content = file_get_contents($path);
    $line = 'APP_KEY=base64:'.base64_encode(random_bytes(32));
    $content = preg_match('/^APP_KEY=.*$/m', $content)
        ? preg_replace_callback('/^APP_KEY=.*$/m', fn () => $line, $content)
        : $content."\n".$line."\n";
    if (file_put_contents($path, $content, LOCK_EX) === false) { fwrite(STDERR, "Cannot write .env.\n"); exit(1); }
    @chmod($path, 0600);
}
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('config:clear');
// After a git pull, apply new migrations here: the Setup page only migrates when it is submitted again.
// Before setup is done there is nothing to update; Setup migrates the fresh database itself.
try {
    if (Illuminate\Support\Facades\Schema::hasTable('local_settings')) {
        if (Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]) !== 0) throw new RuntimeException(trim(Illuminate\Support\Facades\Artisan::output()));
        echo "Database up to date.\n";
    }
} catch (Throwable $e) {
    echo "WARNING: could not update the database (".mb_substr($e->getMessage(), 0, 200)."). Check that MySQL is running, then start again.\n";
}
echo "Local configuration ready.\n";
