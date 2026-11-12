# TrackSphere

**Logistics, Shipment & Last-Mile Delivery Management Platform**

A portfolio-grade logistics platform built with CodeIgniter 4, PHP, MariaDB/MySQL, Bootstrap 5 and REST APIs. It supports merchants, shipment creation, courier allocation, parcel tracking, pickup scheduling, delivery assignments, proof of delivery, failed-delivery handling, returns, COD and operational reporting.

## Product inspiration

TrackSphere takes inspiration from capabilities offered by shipping and last-mile products such as Shiprocket (multi-courier shipping, rate calculation, multiple pickup locations, NDR/RTO, returns, inventory visibility and analytics) and Onfleet (dispatch, proof of delivery and customer notifications). This is an educational portfolio project and is not affiliated with those products.

## Technology

- PHP 8.2+ and CodeIgniter 4 (confirm the installed CI4 version's exact PHP requirements)
- MariaDB 10.6+ or MySQL 8.0+
- Bootstrap 5, HTML, CSS, JavaScript
- REST/JSON APIs, Postman
- Git/GitHub, WAMP/Apache locally
- Later deployment: Linux VPS, Apache/Nginx, HTTPS

## Product roles

1. Platform Admin — organizations and platform configuration.
2. Organization Admin — organization users, pickup locations and settings.
3. Operations Manager — shipments, exceptions, courier allocation and reports.
4. Dispatcher — pickup scheduling and delivery assignment.
5. Delivery Agent — assigned tasks, delivery updates and proof of delivery.
6. Merchant — creates shipments, views tracking and requests returns.
7. Support Executive — tracking issues, NDR and customer support.

The first release uses one organization per user. Every organization-owned query must be scoped using the authenticated user's organization; never trust an organization ID sent by the browser.

## Feature roadmap

### Phase 1 — Foundation
- [ ] Create CI4 project and Git repository.
- [ ] Configure `.env`, database and local environment.
- [ ] Create CI4 migrations for the schema.
- [ ] Authentication, logout and password reset.
- [ ] Organizations, users, roles and permissions (RBAC).
- [ ] Organization-level data isolation.
- [ ] Common API response/error format and audit logging.
- [ ] Postman environment and collection.

### Phase 2 — Master data
- [ ] Customers/merchants and address book.
- [ ] Multiple pickup locations and warehouses.
- [ ] Courier partner master and delivery agents.
- [ ] Package types and configurable service/rate cards.

### Phase 3 — Shipment lifecycle
- [ ] Shipment creation with sender, receiver and parcel details.
- [ ] Unique tracking number generation.
- [ ] Actual, volumetric and chargeable weight calculation.
- [ ] Rate estimates and shipping quote history.
- [ ] Pickup booking and cancellation.
- [ ] Courier allocation and courier tracking reference.
- [ ] Append-only tracking timeline.
- [ ] Public tracking page/API.
- [ ] Shipment label and document upload.

### Phase 4 — Last mile
- [ ] Delivery agent task list and dispatch manifest.
- [ ] Out-for-delivery workflow.
- [ ] Delivery attempts and failure reasons.
- [ ] NDR case creation and resolution.
- [ ] Reattempt scheduling and RTO.
- [ ] Proof of delivery: recipient, photo, signature or simulated OTP.
- [ ] Customer notifications.

### Phase 5 — Commercial operations
- [ ] COD amount and remittance batches.
- [ ] Shipping invoices and invoice line items.
- [ ] Weight discrepancy/billing adjustments.
- [ ] Return request and reverse shipment.
- [ ] Dashboard and date-filtered reports.
- [ ] CSV/Excel report export.

### Phase 6 — API and integrations
- [ ] Merchant API credentials; store only hashes.
- [ ] Token authentication, abilities and revocation.
- [ ] API request logging (never log secrets).
- [ ] Webhook registration and signed delivery.
- [ ] Retry failed webhooks with exponential backoff.
- [ ] Idempotency keys for create/financial operations.
- [ ] Courier adapter interface and simulated courier adapter.
- [ ] Optional real courier integration after tests exist.

### Phase 7 — Production readiness
- [ ] Validation and authorization tests.
- [ ] Unit and feature tests.
- [ ] Pagination, filtering, sorting and query optimization.
- [ ] Rate limiting, CSRF protection and secure headers.
- [ ] Secure private file storage and upload validation.
- [ ] Structured logs and health endpoint.
- [ ] Database backup/restore procedure.
- [ ] Production environment, web server and HTTPS.
- [ ] Deploy and verify live application.

## Shipment lifecycle rules

Normal forward flow:

`draft → ready_to_ship → pickup_scheduled → picked_up → in_transit → reached_destination_hub → out_for_delivery → delivered`

Exception/reverse states include `pickup_failed`, `delivery_failed`, `cancelled`, `rto_initiated`, `rto_in_transit`, `rto_delivered` and return-request states.

**Do not allow arbitrary status changes through generic CRUD.** Implement a `ShipmentStatusService` that validates allowed transitions, updates `shipments.current_status`, and inserts a `shipment_events` record in one DB transaction. Treat the event timeline as append-only.

## Suggested architecture

```text
app/
├── Config/Routes.php
├── Controllers/
│   ├── Web/
│   └── Api/V1/
├── Database/{Migrations,Seeds}/
├── Entities/
├── Filters/
├── Models/
├── Services/
│   ├── Shipment/
│   ├── Tracking/
│   ├── Courier/
│   ├── Webhook/
│   └── Notification/
├── Validation/
├── Views/{layouts,dashboard,tracking}/
└── Tests/{unit,feature}/
```

Keep controllers thin. Put business rules in services and database access in models/repositories. Follow CodeIgniter conventions and create abstractions only when needed.

## API conventions

Base path: `/api/v1`

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/auth/login` | Authenticate |
| GET | `/shipments` | List authorized shipments |
| POST | `/shipments` | Create shipment |
| GET | `/shipments/{id}` | View shipment |
| POST | `/shipments/{id}/book-pickup` | Book pickup |
| POST | `/shipments/{id}/assign-courier` | Assign courier |
| GET | `/tracking/{tracking_number}` | Public tracking |
| POST | `/shipments/{id}/delivery-attempts` | Record delivery attempt |
| POST | `/shipments/{id}/proof-of-delivery` | Submit proof |
| POST | `/shipments/{id}/return-requests` | Request return |
| GET | `/reports/shipment-summary` | Summary report |

Success response example:

```json
{
  "success": true,
  "message": "Shipment created successfully.",
  "data": { "id": 125, "tracking_number": "TS2026XXXXXXXX" },
  "meta": {}
}
```

Validation response example:

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": { "receiver_phone": "Please enter a valid phone number." }
}
```

Use correct HTTP codes (`200`, `201`, `204`, `400`, `401`, `403`, `404`, `409`, `422`, `429`, `500`). Paginate lists, validate all input server-side, version APIs, store timestamps in UTC, and never expose SQL, stack traces, filesystem paths or secrets.

## Database setup

Database name: **`tracksphere_db`**

Bootstrap schema: `database/tracksphere_schema.sql`

1. Start Apache and MariaDB/MySQL in WAMP.
2. Open phpMyAdmin or Navicat.
3. Import `database/tracksphere_schema.sql`.
4. Configure the local `.env` (copy from `env` if needed):

```ini
CI_ENVIRONMENT = development

database.default.hostname = localhost
database.default.database = tracksphere_db
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
database.default.port = 3306
database.default.charset = utf8mb4
database.default.DBCollat = utf8mb4_unicode_ci
```

Change credentials and port for each machine. Never commit `.env` or real credentials. For another computer, clone the repository, run `composer install`, create/import the database, configure `.env`, and run the app.

**Schema maintenance:** the SQL file is a portable initial snapshot. Once CI4 is set up, migrations become the source of truth. Every schema change must be a migration committed to Git. Refresh the SQL snapshot at milestones so a new machine can be provisioned easily.

## Cursor AI working agreement

Cursor is an assistant, not the project architect. Follow these rules for every task:

1. Read this README and inspect the current files before proposing changes.
2. Work on one small module/task at a time; do not generate the entire app in one prompt.
3. Before coding, explain the workflow, affected tables, endpoints, validation and authorization.
4. Follow the existing architecture and naming conventions; do not duplicate files or functionality.
5. Never change schema without creating a CI4 migration.
6. Keep controllers thin; do not put business logic or external API calls directly in controllers.
7. Use transactions for multi-record operations.
8. Do not hardcode credentials, tokens, URLs or machine-specific paths.
9. Add server-side validation, authorization and tests with each feature.
10. Do not silently delete or rewrite unrelated code.
11. Explain generated code and important decisions so the developer can maintain it independently.
12. After each task, report files changed, commands, tests and manual verification steps.
13. Ask before making assumptions about unclear requirements or destructive changes.

### Reusable Cursor prompt

```text
Read README.md and inspect the current project structure first.

We are building TrackSphere, a CodeIgniter 4 logistics platform. Follow
README.md for architecture, database, API, security and coding conventions.

For this task:
1. Explain the business workflow.
2. Identify affected tables, models, services, controllers, routes and views.
3. Identify validation, authorization and security requirements.
4. Propose a small implementation plan and wait for approval before broad changes.
5. Implement only this task; do not rewrite unrelated modules.
6. Add/update tests and explain how to verify the result.
7. Explain the code so I can understand and maintain it myself.

Do not invent existing files or duplicate functionality. Do not change the
schema without a migration. Do not hardcode secrets or environment settings.
```

## Git workflow

- `main`: stable code
- `develop`: integrated development
- `feature/authentication`, `feature/customer-management`, `feature/shipment-lifecycle`: feature branches

Commit examples:

```text
feat: add shipment creation API
fix: prevent duplicate tracking events
test: cover shipment status transitions
docs: update local installation steps
```

Never commit `.env`, credentials, production data, private uploaded documents or database dumps containing real personal information.

## Definition of done

- [ ] Workflow documented and understood.
- [ ] Database migration added.
- [ ] Validation and authorization enforced server-side.
- [ ] API success/error responses consistent.
- [ ] Transactions used where required.
- [ ] Normal and failure cases tested.
- [ ] Postman/manual verification documented.
- [ ] README/API documentation updated.
- [ ] Changes committed to Git.

## Progress tracker

| Milestone | Status | Date | Notes |
|---|---|---|---|
| Scope and architecture | Done | 2026-11-03 | See this document + schema |
| Repository and CI4 setup | Done | 2026-11-03 | `.env`, WAMP, `tracksphere_db` |
| Database bootstrap | Done | 2026-11-03 | SQL import + `DemoSeeder` |
| Authentication and RBAC | Done | 2026-11-03 | Session auth + permission filters |
| Master data | Done | 2026-11-03 | Customers → rate cards + users |
| Shipment lifecycle | Done | 2026-11-03 | Status service + UI + public track |
| Last-mile delivery | Done | 2026-11-03 | Assign, attempts, POD, NDR, returns |
| Commercial ops | Done | 2026-11-03 | Invoices, COD, reports + CSV |
| REST API v1 | Done | 2026-11-03 | Auth token, shipments, tracking, reports |
| In-UI module guides | Done | 2026-11-03 | `Config\Guides` + guide panel |
| Webhooks and integrations | Partial | 2026-11-03 | Schema ready; UI/adapters later |
| Automated tests | Not started | | Add PHPUnit next |
| Deployment | Not started | | VPS + HTTPS later |

## Learning log

| Date | Topic | What I learned | Revisit |
|---|---|---|---|
| | | | |

## Official references

- CodeIgniter 4: https://codeigniter.com/user_guide/
- RESTful resources: https://codeigniter.com/user_guide/incoming/restful.html
- Database and migrations: https://codeigniter.com/user_guide/database/index.html
- Postman: https://learning.postman.com/
- Git: https://git-scm.com/doc

---

**Project principle:** Build it to understand it. A smaller feature implemented correctly—with tests, API documentation and a clear explanation—is better than a large AI-generated feature that cannot be maintained independently.
