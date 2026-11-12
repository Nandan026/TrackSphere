# TrackSphere — Local Setup

Logistics, shipment and last-mile delivery platform (CodeIgniter 4).

## Requirements

- WAMP with **PHP 8.2+** for Apache (project needs `^8.2`)
- MySQL / MariaDB
- Composer

## Quick start

1. Start **Apache** and **MySQL** in WAMP.
2. Ensure Apache PHP is 8.2+ (WAMP tray → PHP → Version).
3. Database is already named `tracksphere_db`. To recreate:

```bash
mysql -u root -e "DROP DATABASE IF EXISTS tracksphere_db; CREATE DATABASE tracksphere_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root tracksphere_db < database/tracksphere_schema.sql
```

4. Copy env if missing: copy `env` → `.env` and set:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost/tracksphere-ci4/public/'
app.indexPage = ''
database.default.database = tracksphere_db
database.default.hostname = localhost
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
database.default.port = 3307
```

> **Important:** This WAMP MySQL runs on port **3307** (not the default 3306). If the port is wrong, the app may talk to a different empty/other MySQL instance.

5. Install dependencies (use PHP 8.2+ CLI):

```bash
C:\wamp64\bin\php\php8.2.30\php.exe C:\ProgramData\ComposerSetup\bin\composer install
```

6. Seed demo data:

```bash
C:\wamp64\bin\php\php8.2.30\php.exe spark db:seed DemoSeeder
```

7. Open: [http://localhost/tracksphere-ci4/public/](http://localhost/tracksphere-ci4/public/)

## Demo logins

Password for all: **`Admin@123`**

| Email | Role |
|---|---|
| admin@demo.tracksphere.local | Organization Admin |
| ops@demo.tracksphere.local | Operations Manager |
| dispatch@demo.tracksphere.local | Dispatcher |
| agent@demo.tracksphere.local | Delivery Agent |
| merchant@demo.tracksphere.local | Merchant |

Public tracking example: `/track/TS2026DEMO000006`

## API

```http
POST /api/v1/auth/login
Content-Type: application/json

{"email":"admin@demo.tracksphere.local","password":"Admin@123"}
```

Use the returned token: `Authorization: Bearer ts_...`

## Module guides

Every authenticated module shows a **How this works** panel (steps + tips). Use it while exploring Customers → Shipments → Delivery → NDR → Returns → Billing → Reports.

## Docs

Full product scope: `tracksphere.md`
