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
echo "Local configuration ready.\n";
