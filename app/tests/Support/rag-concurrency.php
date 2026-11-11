<?php

// Explicit lab-only integration test. Requires the disposable browser demo fixtures.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('RAG_LAB_CONCURRENCY') !== '1' || config('database.connections.pgsql.database') !== 'rag_lab') {
    throw new RuntimeException('Explicit disposable RAG lab required');
}
use App\Knowledge\Versioning;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use App\Models\User;
use App\Rag\Index;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

app(TenantContext::class)->apply(platform: true);
$user = User::withoutGlobalScopes()->where('email', 'admin-a@rag.test')->sole();
app(TenantContext::class)->forUser($user);
$item = KnowledgeItem::where('key', 'noc-lentidao-web')->sole();
$content = KnowledgeVersion::findOrFail($item->current_version_id)->content;
DB::disconnect();
$children = [];
for ($worker = 0; $worker < 2; $worker++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('fork failed');
    }
    if ($pid === 0) {
        try {
            DB::purge();
            app(TenantContext::class)->forUser($user);
            for ($i = 0; $i < 6; $i++) {
                if ($worker === 0) {
                    app(Versioning::class)->draft($item, ['content' => $content."\nRevisão de laboratório ".$i], $user);
                    app(Versioning::class)->publish($item, $user);
                } else {
                    app(Index::class)->refresh($item);
                }
                usleep(10000);
            }
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
    }
    $children[] = $pid;
}
DB::purge();
app(TenantContext::class)->forUser($user);
$samples = 0;
foreach ($children as $pid) {
    do {
        $r = DB::selectOne('SELECT k.current_version_id,d.version_id,d.chunk_count,(SELECT count(*) FROM rag_chunks c WHERE c.document_id=k.id) AS actual,(SELECT count(*) FROM rag_chunks c WHERE c.document_id=k.id AND c.version_id<>k.current_version_id) AS stale FROM knowledge_items k JOIN rag_documents d ON d.id=k.id WHERE k.id=?', [$item->id]);
        if (! $r || $r->current_version_id !== $r->version_id || (int) $r->chunk_count !== (int) $r->actual || (int) $r->stale !== 0) {
            throw new RuntimeException('Mixed version or partial index visible');
        }
        $samples++;
        usleep(20000);
        $done = pcntl_waitpid($pid, $status, WNOHANG);
    } while ($done === 0);
    if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('Concurrent worker failed');
    }
}
if (DB::table('agent_tasks')->count() !== 0) {
    throw new RuntimeException('Unexpected operational task');
}
echo json_encode(['result' => 'PASS', 'publish_cycles' => 6, 'reindex_cycles' => 6, 'consistent_snapshots' => $samples, 'agent_tasks' => 0], JSON_PRETTY_PRINT)."\n";
