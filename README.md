# TrackSphere

**Logistics, Shipment & Last-Mile Delivery Management Platform**

TrackSphere is a logistics management platform designed to manage shipment operations from creation and pickup through delivery, non-delivery resolution, returns, and billing. It provides role-based access for different operational users.

## Purpose

Manage the complete shipment lifecycle:

**Shipment Creation → Pickup → Delivery → Exceptions (NDR/Returns) → Billing**

The platform centralizes logistics operations and provides dedicated access for administrators, operations managers, dispatchers, delivery agents, and merchants.

## Technology Stack

- **Backend:** PHP 8.2+, CodeIgniter 4
- **Database:** MySQL / MariaDB
- **Frontend:** Bootstrap 5, JavaScript
- **API:** REST API (`/api/v1`)

## Features

### Master Data Management
- Customer and pickup location management
- Warehouse and courier management
- Delivery agent management
- Package and rate card management

### Shipment Management
- Shipment lifecycle management
- Shipment tracking timeline
- Public shipment tracking page

### Pickup & Delivery Operations
- Pickup booking
- Delivery desk management
- Delivery agent task assignments
- Proof of delivery

### NDR & Returns
- Non-Delivery Report (NDR) resolution
- Delivery re-attempt management
- Return to Origin (RTO)
- Return approval and rejection

### Billing & Finance
- Invoice management
- Cash on Delivery (COD) remittances
- Operational reports

### User & Access Management
- Role-based access
- User and permission management
- Status glossary and lookup values

## Current Project Status

Core web modules and demo seed data are working across the main operational workflow:

**Master Data → Shipments → Last-Mile Delivery → Returns → Billing**

The project is prepared for local demonstration and portfolio use.

## Database

- **Database name:** `tracksphere_db`
- **Character set:** `utf8mb4`

### Demo Accounts

The following demo accounts are available for local development:

| Email | Role |
|---|---|
| `admin@demo.tracksphere.local` | Organization Admin |
| `ops@demo.tracksphere.local` | Operations Manager |
| `dispatch@demo.tracksphere.local` | Dispatcher |
| `agent@demo.tracksphere.local` | Delivery Agent |
| `merchant@demo.tracksphere.local` | Merchant |

**Demo password for all accounts:** `Admin@123`

> These are demonstration credentials only. Do not use them in a production environment.

## Folder Structure

```text
TrackSphere/
├── app/          Controllers, Models, Services, Views, Config, Database
├── public/       Web root, index.php and public assets
├── system/       CodeIgniter framework core
├── writable/    Cache, logs, sessions and uploads
├── vendor/       Composer dependencies (installed separately)
├── env           Environment configuration template
├── spark         CodeIgniter CLI
├── composer.json Composer dependency configuration
└── README.md     Project documentation
```

## Installation & Setup

### 1. Clone the Repository

```bash
git clone https://github.com/Nandan026/TrackSphere.git
cd TrackSphere
```

### 2. Install Dependencies

Make sure PHP, Composer, and the required PHP extensions are installed.

```bash
composer install
```

### 3. Create the Database

Create a MySQL/MariaDB database using phpMyAdmin or your preferred database tool.

```sql
CREATE DATABASE tracksphere_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

### 4. Import Database Schema & Demo Data

The database schema SQL file is currently maintained separately and is not included in this public repository.

Import the locally available schema into `tracksphere_db` before running the seeders.

After importing the schema, execute:

```bash
php spark db:seed StatusCatalogSeeder
php spark db:seed DemoSeeder
```

### 5. Configure Environment

Copy the `env` file to `.env` in the project root.

Configure the database connection and application URL in `.env`:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost/TrackSphere/public/'

database.default.hostname = localhost
database.default.database = tracksphere_db
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
database.default.port = 3306
```

Update the database username, password, port, and application URL according to your local environment.

### 6. Run the Application

Start Apache and MySQL from your local server environment.

Configure the web server document root to point to the project's `public/` directory, not the project root.

For a local WAMP setup, the application URL may look like:

`http://localhost/TrackSphere/public/`

Log in using any of the demo accounts listed above.

## Security Notes

- Never commit `.env` files or real credentials.
- Do not upload private database dumps or production data.
- Use secure credentials for deployment.
- Change or remove demo credentials before production use.

## Author

**Nandan Patel**

- GitHub: [Nandan026](https://github.com/Nandan026)
- Project Repository: [TrackSphere](https://github.com/Nandan026/TrackSphere)

---

*TrackSphere — Bringing logistics operations together in one platform.*
