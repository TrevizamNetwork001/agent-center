\set ON_ERROR_STOP on
\set VERBOSITY terse
SET log_statement = 'none';
SET log_min_error_statement = 'panic';
\getenv runtime_user RUNTIME_DB_USER
\getenv runtime_password RUNTIME_DB_PASSWORD
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT', :'runtime_user') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname=:'runtime_user') \gexec
ALTER ROLE :"runtime_user" PASSWORD :'runtime_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE :"DBNAME" TO :"runtime_user";
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO :"runtime_user";
SELECT format('GRANT SELECT, INSERT, UPDATE ON TABLE public.%I TO %I', tablename, :'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('agent_operations','users','tenants','agents','agent_enrollments','skills','profiles','diagnostics','diagnostic_steps','agent_tasks','agent_task_attempts') \gexec
SELECT format('GRANT SELECT, INSERT ON TABLE public.%I TO %I', tablename, :'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('audit_events','diagnostic_plans','diagnostic_conclusions') \gexec
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.%I TO %I', tablename, :'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('sessions','cache','cache_locks','jobs','job_batches','failed_jobs','password_reset_tokens') \gexec
SELECT format('GRANT SELECT ON TABLE public.migrations TO %I', :'runtime_user') WHERE to_regclass('public.migrations') IS NOT NULL \gexec
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"runtime_user";

SELECT format('GRANT SELECT, INSERT, UPDATE ON TABLE public.%I TO %I', tablename, :'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('knowledge_items','prompts','llm_providers','llm_models','llm_bindings') \gexec
SELECT format('GRANT SELECT, INSERT ON TABLE public.%I TO %I', tablename, :'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('knowledge_version_contents','prompt_version_contents','version_events','diagnostic_interpretations','interpretation_sources') \gexec
SELECT format('GRANT SELECT ON TABLE public.%I TO %I', viewname, :'runtime_user') FROM pg_views WHERE schemaname='public' AND viewname IN ('knowledge_versions','prompt_versions') \gexec

SELECT format('GRANT SELECT, INSERT, UPDATE ON TABLE public.%I TO %I',tablename,:'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('conversations','conversation_settings') \gexec
SELECT format('GRANT SELECT, INSERT ON TABLE public.%I TO %I',tablename,:'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('conversation_messages','conversation_events') \gexec

SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.agent_maintenance_accesses TO %I', :'runtime_user') WHERE to_regclass('public.agent_maintenance_accesses') IS NOT NULL \gexec

GRANT SELECT, INSERT ON TABLE public.agent_releases TO :"runtime_user";

SELECT format('GRANT SELECT, INSERT ON TABLE public.agent_release_quarantines TO %I', :'runtime_user') WHERE to_regclass('public.agent_release_quarantines') IS NOT NULL \gexec

SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.%I TO %I',tablename,:'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('rag_documents','rag_chunks') \gexec

SELECT format('GRANT SELECT, INSERT, UPDATE ON TABLE public.%I TO %I',tablename,:'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('telegram_bots','telegram_links','telegram_updates','telegram_outbox') \gexec

SELECT format('GRANT SELECT, INSERT, UPDATE ON TABLE public.%I TO %I',tablename,:'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('telegram_groups','telegram_members','telegram_group_contexts') \gexec

SELECT format('GRANT SELECT, INSERT, UPDATE ON TABLE public.noc_devices TO %I', :'runtime_user') WHERE to_regclass('public.noc_devices') IS NOT NULL \gexec
SELECT format('GRANT SELECT, INSERT ON TABLE public.%I TO %I',tablename,:'runtime_user') FROM pg_tables WHERE schemaname='public' AND tablename IN ('noc_device_revisions','noc_simulations') \gexec

-- New laboratory-only resource; does not grant permission to query devices.
SELECT format('GRANT SELECT, INSERT, UPDATE ON TABLE public.device_lab_accesses TO %I', :'runtime_user') WHERE to_regclass('public.device_lab_accesses') IS NOT NULL \gexec
