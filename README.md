# Mark6 Retail Suite (PHP + PostgreSQL / Supabase)

All-in-one, browser-based point of sale and back office for Philippine **pharmacies, groceries / minimarts and hardware stores**:
**POS / cashiering, inventory with lots & expiry, and double-entry accounting in one place.**
Every sale, return, purchase, write-off, petty cash payout and cash advance is posted to the books automatically.

- **Plain PHP 8.1+** — no framework, no Composer packages. Runs on Railway (Docker image included), any PHP host, or a PC in the store.
- **PostgreSQL 13+** — built for **Supabase** (also works with any Postgres).
- Server-rendered pages + a little plain JavaScript (no build step).
- Works on PCs, tablets and phones (Chrome / Edge / Safari); the POS installs as an app and prints to Bluetooth receipt printers.

Mark6 shares its look, layout and workflow with Mark5 Restaurant Suite; the restaurant parts (tables, kitchen slips,
recipes, service charge) are replaced with retail features.

---

## What it does

**POS (cashier screen)**
- Sale-first screen: scan a barcode or tap an item tile, pay, print, next customer. Sales can be put **on hold** and resumed.
- Barcodes for the **retail unit** and for **pack sizes** (1 box = 100 tablets with its own price and barcode), SKU search,
  **weighing-scale barcodes** (prefix + PLU + weight) for produce / meat, `3*` before a scan sells 3 of the next item.
- Price overrides and discounts with **manager PIN** approval; **Senior Citizen / PWD** (VAT-exempt + 20%, names and ID numbers
  recorded for the BIR sales book), 5% senior discount on basic goods, employee / promo presets.
- **Wholesale price** from a minimum quantity, **VAT-exempt items** (maintenance medicines), **Rx items** that ask for the prescription.
- Cash, card, GCash, Maya, bank transfer, split payments, **charge to customer account** (creates a receivable).
- **Returns & refunds** against the original receipt (partial quantities, restock or write off as damaged, refund by cash / card / e-wallet).
- Open day with a change fund, drawer payouts, **X-reading**, blind cash count and **Z-reading** with short / over posted to the books.
- Receipts on 58 mm / 80 mm thermal printers over Bluetooth, USB / serial, the RawBT app or the browser print dialog.

**Inventory**
- Items with generic name, brand, category, selling unit, pack / purchase units, reorder levels, margin % and markup.
- **Lot numbers and expiry dates** (FEFO: sales take the soonest-to-expire lot first), **Expiry Watch** report and dashboard card.
- Purchases / stock in (cash, bank, petty cash or on credit → supplier payable; input VAT), stock issuance for store use,
  **damaged / expired write-offs** by lot, physical counts with variance posting.
- Reports: on hand, reorder, movement, stock card, expiry, lots, margins / price list, slow movers, documents.

**Finance**
- Chart of accounts for retail (Merchandise Inventory, Sales Returns & Allowances, Shrinkage / Damage / Expiry …), journal entries,
  banks, accounts payable / receivable with aging, petty cash, employee cash advances.
- Trial balance, income statement, balance sheet, general ledger, journal, bank register.

**Administration**: users, roles & permissions (Administrator, Manager, Cashier, Sales Clerk, Stock Clerk, Accountant), settings,
discount presets, printer setup per device, **backup & restore** (download / upload .sql, daily automatic backups), audit trail.

---

## Deploy on Railway + Supabase (recommended)

### 1. Create the Supabase database
1. Create a project at [supabase.com](https://supabase.com) and note the **database password**.
2. Open **Project Settings → Database → Connection string**, choose **Session pooler** (works on IPv4 networks such as Railway),
   format **URI**. It looks like
   `postgresql://postgres.xxxxxxxx:[YOUR-PASSWORD]@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres`
3. Nothing else to prepare: Mark6 creates its tables in the `public` schema on first run (set `DB_SCHEMA=mark6` to use a separate schema).

### 2. Deploy the app on Railway
1. Push this repository to GitHub and create a Railway project **from the repo** (Railway detects the `Dockerfile`; `railway.json` sets the `/healthz` health check).
2. Under **Variables** add:
   - `DATABASE_URL` = the Supabase connection string from step 1, with `?sslmode=require` appended
   - `APP_TIMEZONE=Asia/Manila` (optional), `APP_TRUST_PROXY=1`
3. Under **Settings → Networking** generate a domain. Open it: the **installer** appears (database details come from the environment),
   asks for the admin password / manager PIN and lets you start with a **sample pharmacy, grocery or hardware catalog**.
4. Log in as **admin** and open **Administration → Settings** to enter the business name, TIN and receipt details.

Optional: add a **Volume** mounted at `/var/www/html/storage` so downloadable backups survive redeploys (backups are also downloadable
right after they are made, and Supabase keeps its own database backups).

Command line alternative: `railway run php database/install.php --sample=pharmacy` installs without the web form.

### Any other PHP host / VPS
PHP 8.1+ with `pdo_pgsql` and (recommended) `zip`. Point the web server's document root to `public/` (Apache uses `public/.htaccess`;
for nginx route all requests to `public/index.php`), open the site and follow the installer — it writes `config/config.php`.

### Local development
```bash
cp config/config.example.php config/config.php      # edit the PostgreSQL settings (or export DATABASE_URL)
php database/install.php --create-db --sample=pharmacy
php -S 0.0.0.0:8000 -t public public/index.php      # http://localhost:8000  (admin / admin123, PIN 1234)
php tests/run.php                                   # test suite (uses its own schema "mark6_test")
```
`php database/install.php --sample=grocery|hardware` loads the other sample catalogs; without `--sample` the catalog is empty.

Docker locally: `docker build -t mark6 . && docker run -p 8080:8080 -e DATABASE_URL=... mark6`

---

## Using the POS

- **Open Day** with the beginning cash (count by denomination or enter the total).
- Scan or tap items. Scanning a **box barcode** rings up the box at the box price; the stock is kept in retail units.
  Type `3*` (or press **Qty**) before scanning to sell three. Weighing-scale labels are decoded when a scale prefix is set in Settings → POS.
- Tap a line to change the quantity, the price (manager PIN unless the cashier may discount), add a note or give an item discount.
  **Discount** applies a preset to the whole receipt; Senior Citizen / PWD asks for the buyer's name and ID number.
- **Customer** records the name, a charge account and the prescription details for Rx items (asked again at payment when missing).
- **Hold** keeps the sale for later (board under **Held**); **PAY** takes one or more payments and prints the receipt.
- **Receipts** → pick a receipt → **Return / refund** (choose quantities, refund method, restock or write off), **Reprint**, or **Void receipt**.
- **Payout** records small cash expenses from the drawer; **X-Read** shows the day so far; **End of Day** does the blind count and Z-reading.

Settings → POS: block sales below zero stock, scale barcode prefix, expiry warning days. Settings → Tax: VAT registration and rate,
SC/PWD rate, reference-number requirement for card / e-wallet payments.

---

## Bluetooth receipt printers (portable 58 mm printers on Android tablets)

Open **POS → ⎙ printer chip → Open printer setup** (or *Administration → Printer Setup*) on **each device** — printer settings are saved per device.

| Method | Use it when | Notes |
|---|---|---|
| **Bluetooth (BLE)** | Chrome on an Android tablet (or Windows/Mac) with a BLE thermal printer | Prints directly from the POS. Needs https (Railway gives you https) or localhost. |
| **RawBT app** | Android tablet with **any** Bluetooth thermal printer (also classic-Bluetooth-only models) | Install *RawBT* from Google Play, pair the printer in Android Bluetooth settings, choose RawBT in Printer setup. |
| **Serial / COM port** | Windows PC with a USB printer or a Bluetooth printer paired as a COM port | Chrome / Edge, https or localhost. |
| **Browser print** | Printer installed in Windows/macOS, or as a fallback | Normal print dialog. |

Portable-printer features: remembers the printer and **reconnects automatically** when it wakes up; receipts printed while it was
asleep are **queued and printed on reconnect**; adjustable Bluetooth packet size / delay; paper feed after printing; cash drawer kick.
Receipts, refund slips and X/Z readings use the same layout for 58 mm (32 columns) and 80 mm (48 columns) paper.

---

## How the books are kept

| Event | Journal entry |
|---|---|
| Sale | Dr Cash / Card / E-wallet receivable / A/R (charge), Dr Sales Discounts · Cr Sales, Cr Output VAT; Dr COGS · Cr Merchandise Inventory |
| Return / refund | Dr Sales Returns & Allowances, Dr Output VAT · Cr Cash (or card / e-wallet); Dr Inventory (restocked) or Shrinkage (written off) · Cr COGS |
| Receipt void | Reversal of the sale; stock back into the original lots |
| Purchase / stock in | Dr Inventory (+ Input VAT) · Cr Accounts Payable / Cash / Bank / Petty Cash |
| Issuance for store use | Dr expense account · Cr Inventory |
| Damaged / expired write-off | Dr Inventory Shrinkage, Damage & Expiry · Cr Inventory |
| Count variance | Dr/Cr Inventory Variance · Cr/Dr Inventory |
| Drawer payout / petty cash | Dr expense · Cr Cash on Hand / Petty Cash Fund |
| Z-reading short / over | Dr/Cr Cash Short/(Over) · Cr/Dr Cash on Hand |

Selling prices are VAT-inclusive (12%); SC/PWD and VAT-exempt items carry no VAT. Non-VAT businesses switch VAT off in Settings.

---

## Project layout

```
public/            web root: index.php (front controller), assets (css, js), service worker, manifest
app/Core           DB (PDO / PostgreSQL), Router, Request, Response, View, Auth, Csrf, Table, Excel
app/Services       business rules: Items, Inventory, Batches (lots / FEFO), InventoryDocs, Pos/* (Tickets, Returns, CashSessions,
                   Totals), Ledger, Finance/*, Reports/*, Backup, Installer, Seeder (sample catalogs)
app/Controllers    thin HTTP layer;  app/routes/*.php  URL map;  app/nav.php  sidebar
views/             PHP templates;  database/schema.sql  PostgreSQL schema;  tests/  test suite (php tests/run.php)
docker/            Apache / PHP configuration used by the Dockerfile (Railway)
```

Environment variables (see `.env.example`): `DATABASE_URL` or `DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD / DB_SCHEMA / DB_SSLMODE`,
`APP_TIMEZONE`, `APP_DEBUG`, `APP_BASE_PATH`, `APP_TRUST_PROXY`.
