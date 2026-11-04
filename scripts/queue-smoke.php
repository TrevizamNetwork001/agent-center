<?php

// Tooling CLI: verifica a fila técnica sem implementar tarefas de domínio.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$marker = 'foundation.queue-smoke.'.bin2hex(random_bytes(8));
dispatch(static function () use ($marker): void {
    Illuminate\Support\Facades\Cache::put($marker, true, 60);
});

for ($attempt = 0; $attempt < 60; $attempt++) {
    if (Illuminate\Support\Facades\Cache::pull($marker)) {
        echo "Queue database → worker → cache: PASS\n";
        exit(0);
    }
    usleep(500000);
}
fwrite(STDERR, "Worker não confirmou o job técnico em 30s.\n");
exit(1);
