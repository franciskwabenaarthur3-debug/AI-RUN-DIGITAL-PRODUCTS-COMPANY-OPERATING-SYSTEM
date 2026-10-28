# Architecture

Company OS is a Laravel 12 modular monolith. There are no microservices, no Redis and no workflow engine; those are added only when measured needs justify them.

## Request flow

```
Route → middleware (correlation id, auth, active) → Controller
      → Form Request (validation + authorization) → Policy
      → Application Service → Model / Queue Job → Audit record
```

Controllers stay thin. Business rules live in services under `app/Services`. Consequential operations write an audit record through `App\Services\AuditLogger`.

## Layout (Phase 1)

The app uses conventional Laravel directories, with module boundaries expressed by naming (`Lead*`, `Customer*`, `ServicePackage*`). Moving to `app/Modules/<Module>` namespaces is deferred: it would require custom factory and policy registration for little gain at the current size. Revisit when the agent and approval modules land.

| Path | Purpose |
| --- | --- |
| `app/Enums` | `UserRole`, `OperatingStatus`, `ApprovalPolicy` |
| `app/Support/Money.php` | Decimal string ↔ integer minor units, no floats |
| `app/Services/AuditLogger.php` | Append-only audit writes with redaction |
| `app/Services/CompanySettingsService.php` | Settings updates with change audit |
| `app/Http/Middleware/AssignCorrelationId.php` | `X-Request-Id` on every request, stored in Laravel `Context` |
| `app/Http/Middleware/EnsureUserIsActive.php` | Ends sessions of deactivated users |
| `app/Policies` | `CompanySettingPolicy`, `AuditLogPolicy` |
| `app/Console/Commands/CreateUser.php` | `company-os:create-user`; the only way to create accounts |
| `app/Services/LeadService.php` | All lead writes: create, edit, status transitions, follow-ups, notes, opt-out, conversion |
| `app/Services/CustomerService.php`, `ServicePackageService.php` | Customer and package writes with audit |
| `app/Models/Concerns/Searchable.php` | Literal, case-insensitive search scope |
| `app/Support/Domain.php` | Website URL → normalized domain |
| `app/Agents/ToolCatalog.php` | Fixed list of tools agents can be granted |
| `app/Agents/OutputSchema.php` | Validation of agent output |
| `app/Agents/Runtime/*` | Runtime contract, request/result types, normalized failures, `MockRuntime`, `LlmRuntime` (model tool loop), `RuntimeRegistry` |
| `app/Ai/*` | AI gateway: `ModelCatalog`/`ModelConfig` (versioned models and prices), `ModelRequest`/`ModelResponse`, normalized `ProviderError`, `Providers\ModelProvider` interface and `AnthropicProvider` (official SDK) |
| `app/Agents/AgentInstructions.php`, `resources/agents/*` | Fixed policy plus versioned role instructions |
| `app/Services/ProjectService.php`, `app/Services/ReleaseService.php`, `app/Releases/*` | Projects, milestones, deliverables, handover, change requests; releases, quality checks, production approval, deployment record, health check (`HealthChecker`) and rollback |
| `app/Proposals/*`, `app/Services/ProposalService.php` | Proposal pricing, checks and lifecycle; `app/Mail/ProposalMail.php` and `resources/views/proposals/_document.blade.php` are the customer-facing document |
| `app/Services/AiBudgetMonitor.php` | Budget alert and exhaustion status, audited once per month |
| `app/Services/TaskService.php` | Task lifecycle changes (queue, retry, cancel, review, dependencies) |
| `app/Services/TaskRunner.php` | Executes one task: claim, run, finish, with all limits |
| `app/Jobs/RunAgentTask.php` | Queue wrapper for the runner |
| `app/Services/AgentService.php` | Founder-only agent status and permission changes |
| `app/Console/Commands/RecoverStaleRuns.php` | Recovers runs lost by crashed workers |
| `app/Services/MaintenanceService.php` | Maintenance plans, scheduling, visits, findings and live-change approvals |
| `app/Services/AlertService.php` | Deduplicated in-app alerts and the hourly sweep |
| `app/Services/OperatingReportService.php` | Operating reports computed from records; stored snapshots |
| `config/company_os.php` | Allowed currencies, login throttle, redacted keys, AI model catalog, timeouts and retries |

## Decisions

- **Database:** PostgreSQL in all real environments. Tests default to SQLite in-memory for speed; CI also runs the full suite on PostgreSQL. PostgreSQL-only features (the audit trigger) are guarded by driver checks.
- **Authentication:** Hand-written session login using Laravel's `Auth` guard rather than a starter kit, because the app must not offer public registration, password reset by email or profile self-service yet. MFA is planned before the pilot.
- **Money:** integer minor units (`*_minor` columns, `bigint`). Only two-decimal currencies are allowed.
- **AI provider:** Anthropic Claude through the official `anthropic-ai/sdk` package, behind `ModelProvider` so another provider can be added as one class. Agents reference catalog keys, not model ids.
- **Queue:** database driver (`QUEUE_CONNECTION=database`).
- **Frontend:** Blade + Tailwind CSS 4, no JavaScript framework. The mobile menu uses a native `<details>` element.
