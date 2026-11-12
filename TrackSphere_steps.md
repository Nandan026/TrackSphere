# TrackSphere

Logistics, shipment and last-mile delivery management platform.

## Purpose

Manage shipments from create → pickup → delivery → exceptions (NDR/returns) → billing, with role-based access for admin, ops, dispatcher, agent and merchant users.

## Tech stack

- PHP 8.2+ · CodeIgniter 4
- MySQL / MariaDB
- Bootstrap 5 · JavaScript
- REST API (`/api/v1`)

## Features

- Customers, pickup locations, warehouses, couriers, agents, packages, rate cards
- Shipment lifecycle with tracking timeline and public tracking page
- Pickup booking, delivery desk, agent tasks, proof of delivery
- NDR resolve (re-attempt / RTO) and return approve / reject
- Invoices, COD remittances, reports
- Users, roles/permissions, status glossary and lookup values

## Current status

Core web modules and demo seed data are working (master data → shipments → last-mile → returns/billing). Ready for local demo and portfolio use.

## Database

- Name: `tracksphere_db`
- Charset: `utf8mb4`
- Demo seed password for all users: `Admin@123`

| Email | Role |
|-------|------|
| `admin@demo.tracksphere.local` | Organization Admin |
| `ops@demo.tracksphere.local` | Operations Manager |
| `dispatch@demo.tracksphere.local` | Dispatcher |
| `agent@demo.tracksphere.local` | Delivery Agent |
| `merchant@demo.tracksphere.local` | Merchant |

## Folder structure

```text
app/          Controllers, Models, Services, Views, Config, Database
public/       Web root (index.php, assets)
system/       CodeIgniter core
writable/     Cache, logs, sessions, uploads
vendor/       Composer packages (after install)
```

## Setup (clone → run)

1. **Clone and install**
   ```bash
   git clone <your-repo-url>
   cd tracksphere-ci4
   composer install
   ```

2. **Create database**  
   In phpMyAdmin / MySQL, create an empty database:
   ```sql
   CREATE DATABASE tracksphere_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Create tables + demo data**  
   Schema SQL is not in this repo. Import your local schema file into `tracksphere_db` first (phpMyAdmin → Import), then seed:
   ```bash
   php spark db:seed StatusCatalogSeeder
   php spark db:seed DemoSeeder
   ```

4. **Configure `.env`**  
   Copy `env` → `.env` and set:
   ```ini
   CI_ENVIRONMENT = development
   app.baseURL = 'http://localhost/tracksphere-ci4/public/'
   database.default.hostname = localhost
   database.default.database = tracksphere_db
   database.default.username = root
   database.default.password =
   database.default.DBDriver = MySQLi
   database.default.port = 3306
   ```
   Change `baseURL`, username, password and port to match your machine (WAMP often uses `3307`).

5. **Open the app**  
   Start Apache + MySQL, then open:  
   `http://localhost/tracksphere-ci4/public/`  
   Login with any demo email above and password `Admin@123`.

> Point the web server document root at the `public/` folder (not the project root).
