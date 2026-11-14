<?php
// Disposable runner router, not an application route or production entrypoint.
use Illuminate\Http\Request;
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
if (! App\Noc\ReadOnlyLab\RouterOs7\Integration::enabled()) {
    http_response_code(503);
    exit;
}
$scope = json_decode(file_get_contents('/lab/central-scope.json'), true, flags: JSON_THROW_ON_ERROR);
config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => $scope['tenants'], 'device_readonly_lab.enabled' => true,
    'device_readonly_lab.routeros_protocol' => true, 'device_readonly_lab.tenants' => $scope['tenants'], 'llm.key_file' => '/lab/vault']);
$app->handleRequest(Request::capture());
