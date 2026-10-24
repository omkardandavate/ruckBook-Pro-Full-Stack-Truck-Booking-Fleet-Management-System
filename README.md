# TruckBook Pro — Full-Stack Truck Booking & Fleet Management System
**Enterprise Logistics, Fleet Operations, Quotations, Invoicing & Net Profitability Platform**  
**Final-Year Major Capstone Project**  
**Tech Stack:** Native PHP 8+, MySQL 8+ / MariaDB, HTML5, CSS3, JavaScript ES6+, Bootstrap 5.3, Chart.js

---

## 1. System Overview

TruckBook Pro is a complete, production-grade web application engineered to replace manual paperwork, whiteboards, spreadsheets, and informal communications in the commercial freight transportation industry.

The platform provides a connected, transaction-safe workflow across 5 user roles:
$$\text{Freight Request} \longrightarrow \text{Dynamic Tariff Quotation} \longrightarrow \text{Collision-Free Booking} \longrightarrow \text{Fleet Dispatch} \longrightarrow \text{Trip Tracking} \longrightarrow \text{Proof of Delivery (POD)} \longrightarrow \text{Commercial Invoicing} \longrightarrow \text{Payment Reconciliation} \longrightarrow \text{Net Profitability Analytics}$$

---

## 2. Multi-Role Access & One-Click Test Accounts

All accounts are pre-configured with default password: `password123`. The login screen at `login.php` includes an interactive **1-Click Demo Account Quick-Picker** for instant evaluation.

| Role | Email | Password | Primary Functional Capabilities |
| :--- | :--- | :--- | :--- |
| **Super Admin** | `admin@truckbook.com` | `password123` | Full system control, company pricing rules, security audit trail, user accounts |
| **Transport Manager** | `manager@truckbook.com` | `password123` | Fleet inventory, driver rosters, quotation approval, trip dispatch, expense approvals, profit reports |
| **Staff / Operator** | `operator@truckbook.com` | `password123` | Customer onboarding, freight request entry, booking creation, expense entry |
| **Customer** | `customer@example.com` | `password123` | Customer portal: freight requests, accept/reject quotes, track shipments, view invoices |
| **Driver** | `driver@truckbook.com` | `password123` | Driver portal: assigned trips, departure/arrival timestamps, odometer logs, POD image upload |

---

## 3. Project Roadmap: Phases 1 to 8 Summary

| Phase | Description & Deliverables | Status |
| :--- | :--- | :---: |
| **Phase 1: Planning & Requirements** | Problem definition, 5 role matrix, user stories, use cases, SRS document (`docs/SRS_DOCUMENTATION.md`) | **COMPLETED** |
| **Phase 2: Frontend UI Development** | Executive Navy theme (`style.css`), responsive layouts (`responsive.css`), Bootstrap 5, navigation header, topbar, sidebar, footer | **COMPLETED** |
| **Phase 3: Database Design** | 18 normalized MySQL tables, foreign keys, indexes, triggers, database schema (`database/database.sql`), PDO singleton (`config/database.php`) | **COMPLETED** |
| **Phase 4: Authentication & Roles** | Bcrypt hashing, secure sessions, login throttling, CSRF tokens (`includes/csrf.php`), server-side guards (`middleware/require_role.php`) | **COMPLETED** |
| **Phase 5: Core Management Modules** | Customers CRUD, Vehicles CRUD with inspection expirations, Drivers CRUD with CDL tracking, Freight Requests, Dynamic Quotation Calculator, Booking Dispatch | **COMPLETED** |
| **Phase 6: Trip & Financial Operations**| Trip tracking timeline, Odometer logging, POD photo upload, Expense management with approvals, Commercial invoicing, Reconciled payments | **COMPLETED** |
| **Phase 7: Advanced Analytics** | Net Profitability Dashboard, Revenue vs Expenses Chart.js, Expense category share, Lane profitability, CSV Report Export (`modules/reports/export_csv.php`) | **COMPLETED** |
| **Phase 8: QA & Final Submission** | 20 automated tests (`tests/test_business_logic.php`), 19 manual test cases (`docs/TEST_CASES_AND_CHECKLIST.md`), Viva demo guide | **COMPLETED** |

---

## 4. Complete Project Directory Structure

```text
d:/Truck/
├── assets/
│   ├── css/
│   │   ├── style.css             # Executive Navy palette (#0f172a, #1e293b, #2563eb)
│   │   └── responsive.css        # Mobile and tablet responsiveness
│   ├── js/
│   │   └── app.js                # CSRF fetch wrapper, sidebar toggle, confirmation modals
│   └── images/
├── config/
│   ├── app.php                   # Core application bootstrapper & session security
│   └── database.php              # PDO singleton with UTF8MB4 & error exceptions
├── database/
│   ├── database.sql              # Complete 18-table schema + foreign keys + seed data
│   └── seed_demo_data.php        # CLI/Web database migration & seeding runner
├── docs/
│   ├── SRS_DOCUMENTATION.md      # IEEE Software Requirements Specification (SRS)
│   └── TEST_CASES_AND_CHECKLIST.md # QA Test Matrix & Viva Demonstration Checklist
├── includes/
│   ├── auth.php                  # Authentication, login throttling, session regeneration
│   ├── csrf.php                  # Cryptographic CSRF token generation & validation
│   ├── functions.php             # Flash messages, audit logger, notification creator, formatters
│   ├── validation.php            # Fluent server-side Validator class
│   ├── header.php                # Standard HTML head, navbar wrappers, flash renderer
│   ├── topbar.php                # Top sticky header with notification dropdown & user pill
│   ├── sidebar.php               # Role-based navigational menu sidebar
│   └── footer.php                # Footer layout, Chart.js, and Bootstrap bundle
├── middleware/
│   ├── require_login.php         # Unauthenticated user guard
│   └── require_role.php          # Server-side role-based access control enforcer
├── modules/
│   ├── customers/                # Customer CRUD, history & filtering
│   │   ├── index.php             # Search, filter by status, pagination
│   │   ├── create.php            # Add customer with duplicate check
│   │   ├── edit.php              # Edit customer profile
│   │   └── view.php              # 360-degree customer profile & financials
│   ├── vehicles/                 # Fleet inventory, status, document expirations
│   │   ├── index.php             # Fleet table, inspection expiration badges
│   │   ├── create.php            # Register truck (tonnage, permits, fitness)
│   │   ├── edit.php              # Edit specifications
│   │   └── view.php              # Truck profile, statutory compliance, trip history
│   ├── drivers/                  # Driver management, licensing validity, status
│   │   ├── index.php             # Driver roster & CDL expiration warnings
│   │   ├── create.php            # Driver onboarding & mobile portal provisioning
│   │   ├── edit.php              # Update driver profile
│   │   └── view.php              # Driver profile & assigned trips
│   ├── freight_requests/         # Cargo requests from customers & staff
│   │   ├── index.php             # Requests list & status filters
│   │   ├── create.php            # Freight intake form
│   │   └── view.php              # Request details & quote trigger
│   ├── quotations/               # Dynamic quote calculator (km, tonnes, fuel, tolls)
│   │   ├── index.php             # Quotations list & statuses
│   │   ├── create.php            # Automated tariff formula calculator
│   │   ├── view.php              # Printable quotation sheet
│   │   └── action.php            # Accept / Reject action handler
│   ├── bookings/                 # Truck bookings with collision prevention
│   │   ├── index.php             # Bookings list & status filters
│   │   ├── create.php            # Collision-free dispatch & auto-trip creation
│   │   └── view.php              # Printable manifest & cancellation modal
│   ├── trips/                    # Live dispatches, odometer, status timeline, POD upload
│   │   ├── index.php             # Trip dispatches overview
│   │   ├── view.php              # Journey operations, milestone timeline, POD upload
│   │   └── update_status.php     # Milestone updater & POD file processor
│   ├── expenses/                 # Categorized trip expenses & approval workflows
│   │   ├── index.php             # Expense list, category filters, approval actions
│   │   ├── create.php            # Expense submission with receipt image upload
│   │   └── approve.php           # Manager approval action
│   ├── invoices/                 # Tax-compliant invoice generator & balance reconciliation
│   │   ├── index.php             # Invoice ledger & outstanding dues
│   │   ├── create.php            # Invoice generator from booking
│   │   └── view.php              # Printable commercial tax invoice
│   ├── payments/                 # Partial & full payment ledger
│   │   ├── index.php             # Payments transaction ledger
│   │   ├── record.php            # Record payment with balance auto-reconciliation
│   │   └── receipt.php           # Printable official payment receipt
│   ├── reports/                  # Profitability reports, monthly trends & CSV export
│   │   ├── index.php             # Net profitability dashboard with Chart.js
│   │   └── export_csv.php        # Live CSV report download handler
│   └── settings/                 # Company profile, quote pricing rules, audit logs
│       ├── index.php             # Company profile & pricing formula settings
│       └── audit_logs.php        # Immutable security audit trail viewer
├── storage/
│   └── uploads/
│       ├── pod/                  # Uploaded Proof of Delivery documents/photos
│       ├── receipts/             # Uploaded expense receipt scans
│       └── avatars/              # User profile avatars
├── tests/
│   └── test_business_logic.php   # Automated business logic and security test suite
├── .env                          # Local environment and database configuration
├── .env.example
├── .gitignore
├── index.php                     # Gateway router
├── login.php                     # Executive login with 1-click Demo Role Switcher
├── logout.php                    # Safe session terminator
├── register.php                  # Public customer self-registration
├── profile.php                   # User profile & password modification
├── dashboard.php                 # Live operational analytics & status center
└── README.md
```

---

## 5. Development & Installation Guide

### Option 1: XAMPP on Windows (Recommended for Viva/Evaluation)

1. **Start Services in XAMPP Control Panel**:
   - Start **Apache**.
   - Start **MySQL**.

2. **Database Migration**:
   - Open command prompt or PowerShell and run:
     ```bash
     C:\xampp\mysql\bin\mysql.exe -u root < d:\Truck\database\database.sql
     ```
   - Or run the PHP seeding runner:
     ```bash
     C:\xampp\php\php.exe d:\Truck\database\seed_demo_data.php
     ```

3. **Deploy or Symlink to htdocs**:
   - In PowerShell (Run as Administrator):
     ```powershell
     New-Item -ItemType SymbolicLink -Path "C:\xampp\htdocs\truckbook-pro" -Target "d:\Truck"
     ```
   - Open browser and navigate to: `http://localhost/truckbook-pro/`

### Option 2: PHP Built-in Server (Quickest for Development)

1. Ensure MySQL is running on `127.0.0.1:3306`.
2. Open terminal in `d:\Truck` and execute:
   ```bash
   php -S 127.0.0.1:8080 -t d:\Truck
   ```
3. Open browser to: `http://127.0.0.1:8080/`

---

## 6. Running the Automated Test Suite

To verify all security assertions, cryptographic tokens, quotation formulas, and financial reconciliation calculations:

```bash
php tests/test_business_logic.php
```

Expected Output:
```text
========================================================
 TruckBook Pro - Automated Business Logic Test Suite     
========================================================

 [PASS] Password hashing produces secure Bcrypt string
 [PASS] Password verify succeeds with valid password
 [PASS] Password verify rejects wrong password
 [PASS] CSRF token is 64 hex characters (32 bytes)
 [PASS] CSRF validation succeeds with genuine token
 [PASS] CSRF validation fails with forged token
 [PASS] Validator passes with valid inputs
 [PASS] Validator catches multiple form errors
 [PASS] PDO MySQL connection is active and UTF8MB4 compliant
 [PASS] Users table seeded with default roles
 [PASS] Fleet inventory contains vehicles
 [PASS] Driver roster contains drivers
 [PASS] Base haulage calculation accuracy
 [PASS] Subtotal pricing formula accuracy
 [PASS] Grand total quote formula accuracy
 [PASS] Invoice balance reconciliation accuracy
 [PASS] Invoice status logic identifies partially paid
 [PASS] Invoice status transitions to paid on full balance reconciliation
 [PASS] Trip net profit calculation (Revenue - Expenses)
 [PASS] Trip net margin percentage calculation

========================================================
 Test Results Summary: 20 Passed, 0 Failed
========================================================
```
