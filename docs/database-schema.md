# Database schema — Phases 1–6

Application default: MySQL 8+, utf8mb4. Automated tests: SQLite in-memory, isolated through PHPUnit environment settings. No business tables are introduced ahead of their phase.

| Table | Purpose / constraints |
| --- | --- |
| users | PK id; unique email; hashed password; name; email verification; remember token; indexed status default active; nullable UTC last_login_at; timestamps |
| sessions | Session ID PK; indexed optional user_id; IP/user agent; encrypted session payload; indexed last_activity |
| password_reset_tokens | Laravel infrastructure only; email PK, token, creation time; password-reset endpoints are not implemented |
| roles / permissions | Spatie tables; unique name+guard_name |
| model_has_roles / model_has_permissions | Spatie polymorphic model assignments; composite primary keys and indexes; constrained role/permission references |
| role_has_permissions | Composite PK and constrained role/permission references |
| personal_access_tokens | Sanctum infrastructure migration; the application exposes no personal-token creation or acceptance model trait |
| cache / cache_locks | Laravel persistent cache and throttling |
| jobs / job_batches / failed_jobs | Laravel queue infrastructure used by appointment reminders |
| audit_logs | PK id; nullable user_id FK → users with SET NULL; action; UTC created_at; composite user_id+created_at index; no passwords, tokens, or session payloads |

Role seeding is idempotent and transactional, synchronizing 3 roles and 16 permissions. Normal seeding does not create users or customer records. The explicit local setup command creates demonstration users with generated credentials; account creation and role assignment run in a transaction.

Analytics, scheduling, task, automation, and integration additions are described below.

## CRM migration

`2026_10_26_194929_create_crm_tables.php` adds the following, without modifying existing business records.

| Table | Columns and important constraints |
| --- | --- |
| customers | id; unique generated customer_code (CUS + ULID); first_name/last_name; nullable unique normalized email; phone/company/address; indexed active/inactive status; created_by FK → users (RESTRICT); version; timestamps; deleted_at; name and created_at indexes |
| customer_user | customer_id and user_id FKs with cascade deletion; composite PK; reverse user/customer index; timestamps; explicit many-to-many staff assignments |
| pipeline_stages | id; name; unique slug; unique position; probability; timestamps; seven configuration rows seeded through PipelineStageSeeder |
| leads | id; nullable customer_id FK (RESTRICT); assigned_to and created_by FKs → users (RESTRICT); pipeline_stage_id FK (RESTRICT); title/source; estimated_value DECIMAL(15,2); expected_close_date; notes; version; converted_at; timestamps; deleted_at; assignment/stage, stage/created_at and expected_close_date indexes |
| customer_notes | id; customer_id FK (CASCADE); user_id FK (RESTRICT); body; timestamps; customer/created_at index |
| customer_activities | id; customer_id FK (CASCADE); user_id FK (RESTRICT); activity_type; description; timestamps; customer/created_at index |
| lead_activities | id; lead_id FK (CASCADE); user_id FK (RESTRICT); activity_type; description; timestamps; lead/created_at index |

User relationships expose assignedCustomers, assignedLeads, and createdCustomers. Lead status is derived from the stage relationship rather than stored redundantly. The canonical slugs are new, contacted, qualified, proposal, negotiation, won, lost. Their probabilities are configuration, not measured conversion statistics.

Code uniqueness is database-enforced and generated without counting rows. Email uniqueness includes archived customers; null emails may repeat. Existing users are deactivated rather than hard-deleted while restrictive creator/owner FKs exist. UTC timestamps and date-only close dates are serialized separately. No customer or lead demo rows are seeded automatically. Model factories supply test fixtures.

## Phase 3 source tables

`2026_10_26_201335_create_dashboard_source_tables.php` adds:

| Table | Main columns / constraints |
| --- | --- |
| sales | customer_id FK; optional lead_id FK; recorded_by FK; amount DECIMAL(15,2); currency CHAR(3); completed/voided status; UTC closed_at DATETIME; unique request_id UUID; version; voided_at, voided_by FK and void_reason; timestamps; currency/status/closed_at and customer/closed_at indexes |
| tasks | title/description; assigned_to and created_by FKs; optional customer/lead FKs; priority; status; nullable UTC due_date DATETIME; completed_at; timestamps; soft deletes; assignment/creation, status/due, completion and creation indexes |
| appointments | customer and assigned-user FKs; title/description; UTC start_at/end_at DATETIME; status; reminder_sent_at; timestamps; soft deletes; assignment/start and status/start indexes |

These foreign keys restrict hard deletion of referenced business/users records. Sales remain in the ledger when voided. Task and appointment workflows are implemented by the Phase 4/5 additions below.

`2026_10_26_203429_add_dashboard_indexes_to_leads_table.php` adds standalone created_at and assigned_to/created_at indexes for management and staff cohort queries, complementing Phase 2's stage indexes. Both migrations are additive. MySQL session timezone is explicitly +00:00. No stored computed dashboard statistics or seeded business totals are used.

## Phase 4 scheduling migration

`2026_10_26_204431_extend_appointment_scheduling.php` is additive:

| Change | Constraints / use |
| --- | --- |
| appointments.version | Unsigned integer, default 1; compare under lock for all writes |
| appointments.created_by | Nullable users FK with RESTRICT for compatibility with existing Phase 3 rows; new bookings record their actor |
| staff_schedules | user_id PK/FK (CASCADE); timezone; version; timestamps; normalized schedule header |
| staff_availability | id; user_id FK (CASCADE); day_of_week 0 Sunday–6 Saturday; start_time/end_time; timestamps; unique user/day/start; timezone comes from header |
| appointment_reminders | id; appointment_id FK (CASCADE); appointment_version; pending/sent/skipped/failed status; attempts; generic error_code; sent_at; timestamps; unique appointment/version and status/creation indexes |

Existing appointments and their times are retained. No work hours are seeded. Schedule read defaults (UTC, version 0, no windows) describe missing configuration without inserting rows on GET. New window rows use validated local wall times; booking intervals are UTC. Soft deletion retains appointment history/reminder rows. Audit actions record actor, operation and appointment/staff ID without mail credentials or request bodies.

## Phase 5 task and notification migrations

`2026_10_26_213558_extend_task_management.php` adds task version (unsigned, default 1), owner/status/due and status/priority indexes. Existing task data and completion dates are preserved. It creates `task_comments`: task FK CASCADE, author FK RESTRICT, text comment, version default 1, timestamps, task/creation index. Soft-archived parent tasks keep comments but hide them through the API.

`2026_10_26_213559_create_notifications_table.php` adds Laravel’s standard database notification store: UUID PK, type, indexed polymorphic notifiable type/id, text data, nullable read_at, timestamps. Laravel’s polymorphic ownership has no user foreign key; API reads/updates are always constrained through the authenticated user relationship. The stored task notice data is only an integer task_id and fixed allowlisted kind, with no title, comment, customer details, URLs, or credentials. Authorized task context is fetched on demand rather than stored in the alert.

No business records or publicly known passwords are seeded. TaskCommentFactory complements TaskFactory for disposable tests. Task/comment changes audit actor, operation and task ID without storing submitted body text in audit logs.

## Phase 6 automation and integrations

The additive `2026_10_26_220316_create_automation_and_integration_tables.php` migration creates:

| Table | Constraints and purpose |
| --- | --- |
| automation_workflows | name/trigger/configuration JSON; indexed active flag and trigger; creator FK RESTRICT; version; timestamps; soft deletes |
| automation_events | unique event_key; trigger/source type/ID/version; safe context JSON including eligible workflow IDs; occurred_at; indexed nullable processed_at; timestamps |
| automation_logs | workflow/event FKs RESTRICT; unique workflow/event; workflow_version; indexed execution_status; safe execution_data/error code; executed_at; lease_until/token; timestamps |
| automation_steps | run FK CASCADE; unique run/action index; action_type; encrypted configuration; status/attempts/retryable/error_code; timestamps |
| integration_accounts | user FK RESTRICT; unique user/provider/external identity; display email/status; nullable encrypted credentials; timestamps |
| oauth_states | unique SHA-256 state hash; user FK CASCADE; provider/session hash; encrypted verifier; expiry/used_at; no raw OAuth codes stored |
| webhook_endpoints | name/direction/optional URL; encrypted secret; active flag; version; creator FK RESTRICT; timestamps; soft deletes |
| webhook_deliveries | stable unique delivery UUID; endpoint FK RESTRICT; unique step FK RESTRICT; event type; encrypted payload; status/attempts/last attempt; timestamps |
| webhook_receipts | endpoint FK RESTRICT; unique endpoint/delivery and endpoint/nonce; body hash; timestamps; no raw request body |

`2026_10_27_080127_expand_automation_step_configuration.php` expands encrypted action storage to LONGTEXT so valid Unicode bodies fit after JSON/encryption expansion. `2026_10_27_080640_add_automation_origin_to_tasks.php` adds a nullable tasks.automation_log_id FK (RESTRICT). Only automation execution sets this origin; API callers cannot supply it. The overdue scanner excludes generated tasks to prevent recursive chains, while task views and analytics include them.

Source IDs in the event outbox are intentionally typed polymorphic references rather than impossible cross-table FKs; execution independently resolves/scopes the current source. Workflow soft deletes retain execution/delivery history. All operational timestamps are UTC. These tables introduce no sample workflows, business records or credentials.
