<?php

use App\Models\DeviceLabAccess;
use App\Models\NocDevice;
use App\Noc\ReadOnlyAdmin;
use App\Noc\ReadOnlyLab\Ssh;
use Illuminate\Contracts\Console\Kernel;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$manifest = [];
foreach (['app', 'config', 'routes', 'resources', 'database/migrations', 'public'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && ! $file->isLink()) {
            $manifest[substr($file->getPathname(), strlen(base_path()) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }
}
ksort($manifest);
$routes = [];
foreach (app('router')->getRoutes() as $route) {
    $routes[] = ['methods' => $route->methods(), 'uri' => $route->uri(), 'name' => $route->getName(), 'action' => $route->getActionName(), 'middleware' => $route->gatherMiddleware()];
}
usort($routes, fn ($a, $b) => json_encode($a) <=> json_encode($b));
$out = ['files' => $manifest, 'routes' => $routes, 'user' => posix_geteuid(), 'transport_present' => class_exists(Ssh::class), 'credential_model_present' => class_exists(DeviceLabAccess::class), 'credential_migrations' => count(glob(database_path('migrations/*device_lab_accesses*')))];
if (class_exists(ReadOnlyAdmin::class)) {
    $d = (new NocDevice)->forceFill(['manufacturer' => 'huawei', 'platform' => 'huawei-vrp', 'model' => 'NE8000 M4', 'os_version' => 'V800R023C00SPC500']);
    $meta = app(ReadOnlyAdmin::class)->forDevice($d);
    if ($meta['transport'] !== 'NONE' || $meta['operational_enabled'] || $meta['credentials_enabled'] || ! $meta['device_freeze'] || count($meta['contracts']) !== 4) {
        throw new RuntimeException('Unsafe administrative contract');
    }
    $out['admin_contract'] = ['mode' => $meta['mode'], 'transport' => 'NONE', 'contracts' => count($meta['contracts']), 'freeze' => true];
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
