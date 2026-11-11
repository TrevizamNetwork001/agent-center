<?php

use App\Models\ConversationSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Telegram\Accounts;
use App\Telegram\Outbound;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/telegram-transport.php';

$ctx = app(TenantContext::class);
$ctx->apply(platform: true);
if (Tenant::where('slug', 'telegram-lab-a')->exists()) {
    throw new RuntimeException('Fresh lab database required');
}
$password = trim(file_get_contents('/lab/password'));
$demo = [];
foreach (['a', 'b'] as $key) {
    $ctx->apply(platform: true);
    $tenant = Tenant::create(['name' => 'Telegram LAB '.strtoupper($key), 'slug' => 'telegram-lab-'.$key, 'status' => 'active']);
    $user = new User;
    $user->forceFill(['name' => 'Administrador LAB '.strtoupper($key), 'email' => 'admin-'.$key.'@telegram.test', 'password' => Hash::make($password), 'role' => 'tenant_admin', 'tenant_id' => $tenant->id, 'status' => 'active', 'must_change_password' => false])->save();
    $ctx->forUser($user);
    $s = new ConversationSetting;
    $s->forceFill(['tenant_id' => $tenant->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $user->id])->save();
    $demo[$key] = ['tenant' => $tenant->id, 'user' => $user->id];
}
$ctx->apply($demo['a']['tenant'], $demo['a']['user']);
$user = User::findOrFail($demo['a']['user']);
$ctx->forUser($user);
$bot = app(Accounts::class)->configure($user, '123456789:'.str_repeat('f', 35));
app(Accounts::class)->register($bot, $user, true);
$demo['bot'] = $bot->id;
$demo['secret'] = Crypt::decryptString($bot->secret_cipher);
$out = new Outbound;
$out->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'generation' => $bot->generation, 'chat_id' => 12345, 'body_cipher' => Crypt::encryptString('Entrega sintética de homologação.'), 'status' => 'UNKNOWN', 'last_error' => 'DELIVERY_UNKNOWN', 'next_attempt_at' => now()])->save();
file_put_contents('/lab/demo.json', json_encode($demo));
chmod('/lab/demo.json', 0600);
echo "LAB seeded: two synthetic tenants, encrypted bot fixture and uncertain delivery example.\n";
