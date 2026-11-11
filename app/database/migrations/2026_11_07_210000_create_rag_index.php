<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE rag_documents (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, version_id uuid NOT NULL,
 digest char(64) NOT NULL, algorithm integer NOT NULL DEFAULT 1,
 chunk_count integer NOT NULL, indexed_at timestamptz NOT NULL,
 UNIQUE(id,tenant_id),
 FOREIGN KEY(id,tenant_id) REFERENCES knowledge_items(id,tenant_id),
 FOREIGN KEY(version_id,id,tenant_id) REFERENCES knowledge_version_contents(id,knowledge_item_id,tenant_id)
);
CREATE TABLE rag_chunks (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, document_id uuid NOT NULL, version_id uuid NOT NULL,
 position integer NOT NULL CHECK(position>=0), offset_start integer NOT NULL, offset_end integer NOT NULL,
 title varchar(160) NOT NULL, section varchar(200) NOT NULL, content text NOT NULL,
 search tsvector GENERATED ALWAYS AS (
 setweight(to_tsvector('portuguese',title),'A') || setweight(to_tsvector('portuguese',content),'B') ||
 setweight(to_tsvector('simple',content),'C')) STORED,
 UNIQUE(document_id,version_id,position),
 FOREIGN KEY(document_id,tenant_id) REFERENCES rag_documents(id,tenant_id),
 FOREIGN KEY(version_id,document_id,tenant_id) REFERENCES knowledge_version_contents(id,knowledge_item_id,tenant_id)
);
CREATE INDEX rag_search ON rag_chunks USING gin(search);
CREATE INDEX rag_chunks_tenant ON rag_chunks(tenant_id,document_id,version_id);
SQL);
        foreach (['rag_documents', 'rag_chunks'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_access ON $table USING(tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK(tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE rag_chunks,rag_documents');
    }
};
