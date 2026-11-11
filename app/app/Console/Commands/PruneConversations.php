<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\ConversationSetting;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneConversations extends Command
{
    protected $signature = 'agent-center:prune-conversations {tenant : Explicit tenant ID} {--apply : Privileged migrator connection required}';

    protected $description = 'Preview or purge expired, closed conversation history; preserve diagnostic evidence';

    public function handle(TenantContext $ctx): int
    {
        try {
            $id = filter_var($this->argument('tenant'), FILTER_VALIDATE_INT);
            if (! $id || $id < 1) {
                return 1;
            }$ctx->apply(tenantId: $id);
            $days = ConversationSetting::value('retention_days') ?? 30;
            $count = DB::transaction(function () use ($days) {
                $rows = Conversation::whereIn('status', ['CLOSED', 'CANCELLED'])->where('last_activity_at', '<', now()->subDays($days))->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('diagnostics')->whereColumn('diagnostics.id', 'conversations.current_diagnostic_id')->whereIn('diagnostics.status', ['PENDING', 'RUNNING']);
                })->orderBy('id')->limit(500)->lock('FOR UPDATE SKIP LOCKED')->get();
                if ($this->option('apply')) {
                    foreach ($rows as $row) {
                        // Privileged retention also clears channel references; ordinary runtime keeps no DELETE grant.
                        DB::table('telegram_links')->where('conversation_id', $row->id)->update(['conversation_id' => null, 'cursor' => 0]);
                        DB::table('telegram_outbox')->where('conversation_id', $row->id)->delete();
                        DB::table('conversation_events')->where('conversation_id', $row->id)->delete();
                        DB::table('conversation_messages')->where('conversation_id', $row->id)->delete();
                        $row->delete();
                    }
                }

                return $rows->count();
            });
            $this->line(json_encode(['eligible' => $count, 'applied' => (bool) $this->option('apply'), 'batch_limit' => 500]));

            return 0;
        } catch (\Throwable) {
            $this->error('Purge unavailable; verify privileged connection and tenant. No payload emitted.');

            return 1;
        } finally {
            $ctx->apply();
        }
    }
}
