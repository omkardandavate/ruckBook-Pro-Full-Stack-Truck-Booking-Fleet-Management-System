# Quality Assurance, Test Cases & Project Submission Checklist
## TruckBook Pro — Full-Stack Truck Booking & Fleet Management System

---

## 1. Automated Test Execution

The automated test script validates core business logic, hashing algorithms, CSRF security, input validators, quotation pricing formulas, and invoice reconciliation calculations.

```bash
php tests/test_business_logic.php
```

### Automated Test Results
- **Password Security:** Bcrypt hashing verified; plain-text rejected.
- **CSRF Defense:** Cryptographic token length (64 hex characters) and constant-time validation verified.
- **Form Validator:** Email, phone, date range, string length, and numeric boundary tests verified.
- **Tariff Formula:** Base rate, tonnage surcharge, fuel surcharge index, and tax calculation confirmed.
- **Invoice & Balance Reconciliation:** Partial and full balance clearing logic verified.
- **Net Trip Profitability:** Revenue minus expenses accounting verified.
- **Overall Result:** **20 Passed, 0 Failed.**

---

## 2. Comprehensive System Test Case Matrix

| Test Case ID | Module / Feature | Test Description | Input / Action | Expected Result | Status |
| :--- | :--- | :--- | :--- | :--- | :---: |
| **TC-01** | Authentication | Valid login credentials | `admin@truckbook.com` + `password123` | Session initiated, ID regenerated, redirects to `dashboard.php` | **PASS** |
| **TC-02** | Authentication | Invalid login credentials | `admin@truckbook.com` + `wrongPassword` | Rejection alert, attempt logged, no session created | **PASS** |
| **TC-03** | Security | CSRF Protection | POST request without valid `csrf_token` | Blocked with 403 Forbidden security alert | **PASS** |
| **TC-04** | Security | Role-Based Guard | Customer attempts to access `modules/settings/index.php` | Blocked with 403 Access Denied page | **PASS** |
| **TC-05** | Customers | Customer Creation | Duplicate email address | Server validation error: customer already exists | **PASS** |
| **TC-06** | Fleet Management | Truck Registration | Duplicate registration plate number | Unique key constraint violation trapped; friendly alert displayed | **PASS** |
| **TC-07** | Fleet Compliance | Expired Documents | Vehicle with expired insurance or fitness certificate | Expiration warning badges displayed; blocked from trip assignment | **PASS** |
| **TC-08** | Driver Roster | Driver Onboarding | CDL license expiration check | Driver registered; if expired, blocked from dispatch | **PASS** |
| **TC-09** | Quotation Engine | Dynamic Tariff Calculation | Distance = 500 KM, Weight = 20T, Tolls = $100 | Formula calculates exact Subtotal, Tax (18%), and Grand Total | **PASS** |
| **TC-10** | Quotation Workflow| Customer Decision | Customer clicks "Accept Quotation" | Quote status updates to `accepted`; notifies dispatch manager | **PASS** |
| **TC-11** | Booking Dispatch | Collision Prevention | Attempt to assign vehicle currently `on_trip` | Vehicle filtered out or blocked by database transaction check | **PASS** |
| **TC-12** | Trip Operations | Milestone Progression | Dispatched &rarr; In Transit &rarr; Arrived &rarr; Delivered | Timeline updates; timestamps recorded; milestones logged | **PASS** |
| **TC-13** | Delivery & POD | Proof of Delivery Upload | Upload delivery note image (`.jpg`) and consignee name | File moved to `storage/uploads/pod/`; status set to `delivered` | **PASS** |
| **TC-14** | Invoicing | Invoice Generation | Bill completed booking | Commercial invoice generated with balance = total; status = `unpaid` | **PASS** |
| **TC-15** | Reconciliation | Payment Credit | Partial payment recorded against invoice | Balance reduced; status transitions to `partially_paid` | **PASS** |
| **TC-16** | Reconciliation | Full Balance Cleared | Final payment matching remaining balance recorded | Balance reduced to $0.00; status transitions to `paid` | **PASS** |
| **TC-17** | Profitability | Net Margin Accounting | Trip Revenue: $2,719.90, Expenses: $325.50 | Net Profit = $2,394.40; Profitability margin = 88.0% | **PASS** |
| **TC-18** | Reports | CSV Export | Click "Export to CSV" on Profitability report | Downloads `truckbook_profitability_report_*.csv` with clean data | **PASS** |
| **TC-19** | Responsive Design | Mobile Viewport Check | View in 375px mobile screen | Off-canvas drawer menu toggles; tables scroll horizontally; clean cards | **PASS** |

---

## 3. Step-by-Step Viva & Final Demonstration Guide

When presenting the project to examiners or evaluators, follow this streamlined 5-minute demonstration:

1. **Step 1: Portal Login & One-Click Role Switcher**
   - Open browser to `http://127.0.0.1:8080/login.php`.
   - Point out the **Quick Demo Account Selector** buttons at the bottom.
   - Click **Super Admin** to show the live executive dashboard with Chart.js charts and live stats.
2. **Step 2: Fleet Management & Statutory Compliance**
   - Click **Fleet / Vehicles** in the sidebar.
   - Show how the system tracks commercial insurance, permits, and fitness inspection dates.
   - Show the red warning badge on `TRK-MI-4001` (Inspection Expired / Maintenance).
3. **Step 3: Driver Roster & Licensing Validity**
   - Click **Drivers** in the sidebar.
   - Show John Doe (active on trip) and Vikram Patel (license expired warning).
4. **Step 4: End-to-End Freight Quotation & Booking Flow**
   - Click **Freight Requests** &rarr; View `REQ-2026-0001`.
   - Click **Generate Quotation** to showcase the automated calculation engine (distance $\times$ $/KM, tonnage, fuel index, tolls, taxes).
   - Show the generated printable quotation in `modules/quotations/view.php`.
5. **Step 5: Trip Milestone Tracking & Proof of Delivery (POD)**
   - Click **Trips & Tracking** &rarr; View `TRP-2026-0001`.
   - Demonstrate the journey timeline, odometer tracking, and uploaded Proof of Delivery document.
6. **Step 6: Commercial Invoicing & Payment Reconciliation**
   - Click **Invoices** &rarr; View `INV-2026-0001`.
   - Show the itemized freight breakdown and the **Recorded Reconciled Payments** table.
   - Point out that the remaining balance updates in real-time as payments clear.
7. **Step 7: Trip Profitability & CSV Export**
   - Click **Profitability & Reports**.
   - Show the Net Profit KPI card ($\text{Recognized Revenue} - \text{Approved Expenses} = \text{Net Margin}$).
   - Show the Monthly Revenue bar chart and Expense category doughnut chart.
   - Click **Export to CSV** to prove live data generation!
