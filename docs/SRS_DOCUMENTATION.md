# Software Requirements Specification (SRS)
## Project: TruckBook Pro — Full-Stack Truck Booking & Fleet Management System
**Document Version:** 1.0.0  
**Prepared For:** Major Final-Year Project Evaluation  
**Technologies:** PHP 8+, MySQL 8+, HTML5, CSS3, JavaScript ES6+, Bootstrap 5.3, Chart.js  

---

## 1. Introduction

### 1.1 Purpose
This document specifies the software requirements for **TruckBook Pro**, an enterprise web application designed for commercial transport companies, fleet operators, independent truck owners, dispatchers, drivers, and corporate freight clients. It details system capabilities, user roles, functional workflows, non-functional constraints, interface wireframes, and architectural boundaries.

### 1.2 Scope of the System
TruckBook Pro replaces disparate paper registers, whiteboards, spreadsheets, and manual phone calls with a centralized, role-secured digital platform. The software governs the lifecycle of freight transportation:
1. **Intake & Tariffing:** Freight request logging, multi-variable quotation calculation (distance, tonnage, fuel surcharge, tolls, driver allowances), and quotation revision/approval.
2. **Dispatch & Assignment:** Conflict-free assignment of licensed drivers and roadworthy trucks.
3. **Journey & Milestone Execution:** Live trip milestone tracking (`scheduled`, `dispatched`, `in_transit`, `arrived`, `unloading`, `delivered`), odometer logging, and digital Proof of Delivery (POD) image capture.
4. **Expense Accounting:** Categorized trip expenditure logging (fuel, tolls, food/lodging allowances, unloading fees, en-route repairs) with administrative approval workflows.
5. **Billing & Financial Reconciliation:** Tax-compliant commercial invoice generation, partial and full payment tracking, ledger reconciliation, and net trip profitability reporting ($\text{Net Profit} = \text{Revenue} - \text{Expenses}$).

### 1.3 Definitions, Acronyms, and Abbreviations
- **POD:** Proof of Delivery (consignee signature, stamped freight receipt, or delivery photo).
- **RBAC:** Role-Based Access Control.
- **CSRF:** Cross-Site Request Forgery.
- **PDO:** PHP Data Objects.
- **Odometer:** Instrument indicating distance traveled by a vehicle in kilometers.
- **Net Margin:** Recognized freight revenue minus approved en-route operational expenses.

---

## 2. Overall Description

### 2.1 Product Perspective
TruckBook Pro is a standalone web application architected using a modular Model-View-Controller (MVC) oriented PHP structure. It interfaces directly with a relational MySQL database via PDO prepared statements and presents a responsive executive user interface powered by Bootstrap 5 and Chart.js.

```
+-----------------------------------------------------------------------------------+
|                               CLIENT LAYER (BROWSER)                              |
|   Desktop / Tablet / Mobile UI  *  Bootstrap 5.3  *  Chart.js  *  Fetch API / AJAX|
+-----------------------------------------+-----------------------------------------+
                                          | HTTPS / HTTP Requests
                                          v
+-----------------------------------------------------------------------------------+
|                             APPLICATION / SERVER LAYER                            |
|       Routing & Entrypoints (login.php, dashboard.php, modules/*, api/*)          |
|-----------------------------------------------------------------------------------|
|     Security & Middleware: require_login.php, require_role.php, csrf.php          |
|-----------------------------------------------------------------------------------|
|   Business Modules: Customers, Vehicles, Drivers, Quotes, Bookings, Trips, Invoices|
|-----------------------------------------------------------------------------------|
|     Helpers & Validation: Validator (validation.php), Auth (auth.php), Audit Log  |
+-----------------------------------------+-----------------------------------------+
                                          | PDO Prepared Queries (utf8mb4)
                                          v
+-----------------------------------------------------------------------------------+
|                                DATABASE LAYER (MYSQL)                             |
|  18 Relational Tables (InnoDB, Foreign Keys, Indexes, Transactions, Audit Trail)  |
+-----------------------------------------------------------------------------------+
```

### 2.2 User Personas & Permissions Matrix

| Permission / Action | Super Admin | Transport Manager | Staff / Operator | Customer | Driver |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **System Settings & Audit Logs** | Yes | No | No | No | No |
| **User & Staff Account Provisioning** | Yes | View Only | No | No | No |
| **Fleet & Vehicle Inventory CRUD** | Yes | Yes | View Only | No | Assigned Only |
| **Driver Roster & License Tracking** | Yes | Yes | View Only | No | Own Profile |
| **Customer Intake & Management** | Yes | Yes | Yes | Own Profile | No |
| **Freight Request Submission** | Yes | Yes | Yes | Yes | No |
| **Quotation Generation & Revision** | Yes | Yes | View Only | View / Accept | No |
| **Booking Creation & Dispatch** | Yes | Yes | Yes | No | No |
| **Vehicle & Driver Assignment** | Yes | Yes | No | No | No |
| **Trip Milestone Status Updates** | Yes | Yes | Yes | View Tracking | Update Status |
| **Proof of Delivery (POD) Upload** | Yes | Yes | Yes | View POD | Upload POD |
| **Trip Expense Entry** | Yes | Yes | Yes | No | Enter Expenses |
| **Trip Expense Approval** | Yes | Yes | No | No | No |
| **Invoice Generation & Billing** | Yes | Yes | View Only | View Invoices | No |
| **Payment Recording & Reconciliation**| Yes | Yes | No | View Receipts | No |
| **Profitability & Financial Reports**| Yes | Yes | No | No | No |

---

## 3. Detailed Use Case Specifications

### Use Case UC-01: Submit Freight Request & Auto-Generate Quotation
- **Primary Actor:** Customer / Staff Operator
- **Preconditions:** User is authenticated; customer profile exists in database.
- **Main Success Scenario:**
  1. Customer enters pickup address, delivery address, destination city, cargo description, estimated tonnage (e.g. 18.5 tonnes), preferred departure date, and required truck type (container, flatbed, refrigerated).
  2. System validates input against minimum tonnage ($>0.1$) and future/current date.
  3. System calculates route tariff using formula:
     $$\text{Estimated Quote} = (\text{Distance} \times \text{Base Rate/KM}) + (\text{Tonnage} \times \text{Tonne Rate}) + \text{Fuel Surcharge} + \text{Estimated Tolls} + \text{Taxes}$$
  4. System generates unique request reference (e.g. `REQ-2026-0001`) and draft quotation (e.g. `QUO-2026-0001`).
  5. Notification dispatched to Transport Manager queue.
- **Postconditions:** Freight request and quotation stored with status `sent`; customer can review and accept online.

### Use Case UC-02: Collision-Free Booking Dispatch & Resource Assignment
- **Primary Actor:** Transport Manager
- **Preconditions:** Customer has accepted quotation or staff initiates direct booking.
- **Main Success Scenario:**
  1. Manager selects booking for dispatch.
  2. System filters vehicle roster to display only vehicles where:
     - `status = 'available'`
     - `truck_type` matches requested container/flatbed type
     - `capacity_tonnes` $\ge$ cargo weight
     - `fitness_expiry`, `insurance_expiry`, and `permit_expiry` are active ($>\text{Planned Delivery Date}$).
  3. System filters driver roster to display only drivers where:
     - `status = 'available'`
     - `licence_expiry` is active ($>\text{Planned Delivery Date}$).
  4. Manager confirms selection.
  5. System begins database transaction:
     - Updates booking status to `confirmed`.
     - Creates new trip record with status `scheduled`.
     - Updates vehicle status to `on_trip`.
     - Updates driver status to `on_trip`.
     - Commits transaction and writes audit log.
- **Exception Flow (Resource Conflict):** If selected vehicle was assigned in parallel, transaction rolls back and displays conflict alert.

### Use Case UC-03: Trip Milestone Progression & Proof of Delivery (POD)
- **Primary Actor:** Fleet Driver / Transport Staff
- **Preconditions:** Trip is in `scheduled` or `dispatched` state.
- **Main Success Scenario:**
  1. Driver navigates to assigned trip on mobile browser.
  2. Driver enters start odometer reading and marks status as `dispatched` then `in_transit`.
  3. Upon reaching delivery depot, driver updates status to `arrived`, then `unloading`.
  4. Upon cargo handoff, driver captures receiver's name and uploads photo of stamped paper delivery note or physical signature.
  5. System validates image file (PNG/JPG/PDF $\le 5$ MB), moves it to `storage/uploads/pod/`, updates trip status to `delivered`, and records actual arrival timestamp.
  6. Vehicle and driver statuses automatically revert to `available`.
  7. Consignee and Manager receive real-time delivery notifications.

### Use Case UC-04: Invoice Generation & Payment Reconciliation
- **Primary Actor:** Transport Manager / Finance Staff
- **Preconditions:** Booking is completed or delivered.
- **Main Success Scenario:**
  1. Staff initiates invoice generation for completed booking.
  2. System pulls agreed freight rate, calculates tax (e.g., 18% GST/VAT), creates itemized line items, and generates invoice number (`INV-2026-0001`).
  3. Balance amount is set to total amount; payment status is set to `unpaid`.
  4. When customer transmits payment, staff logs payment record: amount, payment method (wire, cheque, card, UPI), transaction reference.
  5. System computes new balance:
     $$\text{Balance Amount} = \text{Invoice Total} - \sum(\text{Payments})$$
  6. If balance equals zero, invoice status automatically updates to `paid`. If $0 < \text{Balance} < \text{Total}$, status updates to `partially_paid`.
  7. System produces printable receipt and invoice.

---

## 4. UI/UX Wireframes

### Wireframe 1: Executive Dashboard (Admin / Manager)
```
+----------------------------------------------------------------------------------------------+
| [TRUCKBOOK PRO]     Platform / Dashboard             [🔔 Notifications (3)]  [👤 Alex Admin] |
+---------------------+------------------------------------------------------------------------+
| MAIN                |  DASHBOARD OVERVIEW                                                    |
| > Dashboard         |  Real-time status of freight movements, fleet, and financial balances  |
|                     |                                                                        |
| OPERATIONS          |  +---------------+  +---------------+  +---------------+  +---------------+ |
| > Customers         |  | TOTAL BOOKINGS|  | ACTIVE TRIPS  |  | FLEET AVAIL.  |  | NET PROFIT    | |
| > Fleet / Vehicles  |  |      24       |  |       6       |  |  18 / 22 Ready|  |   $48,920.00  | |
| > Drivers           |  +---------------+  +---------------+  +---------------+  +---------------+ |
|                     |                                                                        |
| DISPATCH & TRIPS    |  +-------------------------------------+  +--------------------------+ |
| > Freight Requests  |  | MONTHLY REVENUE & EXPENSES (CHART)  |  | FLEET STATUS (DONUT)     | |
| > Quotations        |  |  $50k | [Bar Chart]                 |  |  🟢 18 Available         | |
| > Bookings          |  |  $25k |                             |  |  🔵 4 On Trip            | |
| > Trips & Tracking  |  |    $0 +-------------------------    |  |  🔴 2 Maintenance        | |
| > Trip Expenses     |  |         May   Jun   Jul   Aug       |  |                          | |
|                     |  +-------------------------------------+  +--------------------------+ |
| BILLING & FINANCE   |                                                                        |
| > Invoices          |  REAL-TIME TRIP DISPATCHES & STATUS                                    |
| > Payments          |  +--------+-----------+----------------+----------+-----------+------+ |
|                     |  | Trip # | Route     | Vehicle        | Driver   | Status    | Action|
| ANALYTICS           |  | TRP-01 | CHI->DET  | TRK-1001 (Box) | John Doe | In Transit| [View]|
| > Profit Reports    |  | TRP-02 | LA->SLC   | TRK-2001 (Ref) | C. Rod   | Dispatched| [View]|
| > Settings & Audit  |  +--------+-----------+----------------+----------+-----------+------+ |
+---------------------+------------------------------------------------------------------------+
```

### Wireframe 2: Freight Request & Dynamic Quotation Calculator
```
+----------------------------------------------------------------------------------------------+
| CREATE FREIGHT QUOTATION REQUEST                                                             |
+----------------------------------------------------------------------------------------------+
| Customer: [ Select Customer: Acme Logistics Corp      v ]                                    |
| Pickup City:    [ Chicago, IL         ]  Delivery City:   [ Detroit, MI         ]            |
| Pickup Address: [ 742 Industrial Ave  ]  Delivery Address:[ Gate 3, Assembly Rd ]            |
| Cargo Weight:   [ 18.50 ] Tonnes         Preferred Date:  [ 2026-10-20          ]            |
| Truck Type:     [ Container (25T)   v ]  Cargo Note:      [ Automotive stamping tooling ]    |
|----------------------------------------------------------------------------------------------|
| LIVE TARIFF COMPUTATION ENGINE:                                                              |
| Distance (Estimated): 460 KM             Base Rate ($4.00/KM):           $1,840.00           |
| Cargo Surcharge ($15.00/Tonne): $277.50   Fuel Surcharge (10%):           $ 211.75           |
| Tolls & Turnpike Estimate:      $ 85.00   Driver Overnight Allowance:     $ 150.00           |
|----------------------------------------------------------------------------------------------|
| Subtotal: $2,564.25    Tax (18% GST/VAT): $461.57    TOTAL QUOTE AMOUNT: $3,025.82           |
| [ Save Draft ]                    [ Submit & Send Official Quotation to Client ]             |
+----------------------------------------------------------------------------------------------+
```

### Wireframe 3: Driver Trip Execution & Proof of Delivery (POD)
```
+----------------------------------------------------------------------------------------------+
| DRIVER TRIP PORTAL — TRIP #TRP-2026-0001                                                    |
+----------------------------------------------------------------------------------------------+
| Origin: Chicago Logistics Hub   --->   Destination: Detroit Assembly Plant 3                 |
| Assigned Vehicle: TRK-IL-1001 (Volvo FH16)     Cargo: 18.50 Tonnes Tooling                   |
| Start Odometer:   [ 142,500 ] KM               Departure Time: Oct 14, 2026, 09:30 AM        |
|                                                                                              |
| TRIP STATUS MILESTONE PROGRESSION:                                                           |
| (•) Scheduled  ==>  (•) Dispatched  ==>  (•) In Transit  ==>  ( ) Arrived  ==>  ( ) Delivered|
|                                                                                              |
| DELIVERY CONFIRMATION & PROOF OF DELIVERY (POD):                                             |
| End Odometer Reading: [ 142,960 ] KM (Total: 460 KM)                                         |
| Consignee / Receiver Name: [ Robert Henderson, Receiving Supervisor ]                         |
| Upload Delivery Slip / Photo / Stamped Receipt:                                              |
| [ 📷 Choose File / Take Photo: pod_stamped_note.jpg ]                                         |
| Receiver Digital Signature (Optional): [ Draw / Sign Here ]                                  |
| [ Complete Delivery & Transmit POD ]                                                         |
+----------------------------------------------------------------------------------------------+
```

---

## 5. Non-Functional Requirements & Security Protocols
1. **Password Storage:** One-way salted hash generated using PHP's native `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10])`. Plain-text credentials are never stored.
2. **Session Hardening:**
   - `session.cookie_httponly = 1`
   - `session.use_only_cookies = 1`
   - `session.cookie_samesite = Lax`
   - Automatic execution of `session_regenerate_id(true)` upon successful user login to thwart session fixation exploits.
3. **CSRF Countermeasures:** Every state-altering HTTP request (`POST`, `PUT`, `DELETE`) is guarded by a 256-bit cryptographically pseudorandom token validated via constant-time comparison `hash_equals()`.
4. **Injection Prevention:** 100% of SQL transactions employ PDO prepared statements with strict parameter type binding. Zero concatenated user strings.
5. **Role Gatekeeping:** Server-side middleware verifies roles before script execution. Front-end button hiding is treated strictly as an aesthetic aid, not a security boundary.
