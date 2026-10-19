-- Mark6 Retail Suite — PostgreSQL schema (Supabase, Railway Postgres or a local PostgreSQL 13+)
-- Money uses NUMERIC(14,2); quantities use NUMERIC(14,4) (e.g. 0.125 kg). Flags are SMALLINT 0/1.
-- Run through the installer (/install or `php database/install.php`), which also loads seed data.
-- Every statement is re-runnable (IF NOT EXISTS). Foreign keys are DEFERRABLE so a backup can be restored in one transaction.

CREATE TABLE IF NOT EXISTS settings (
  "key" VARCHAR(64) PRIMARY KEY,
  "value" TEXT NULL
);

-- Document number series, e.g. SI-00000001, RR-000001
CREATE TABLE IF NOT EXISTS sequences (
  name VARCHAR(32) PRIMARY KEY,
  prefix VARCHAR(16) NOT NULL,
  next_no INTEGER NOT NULL DEFAULT 1,
  pad SMALLINT NOT NULL DEFAULT 6
);

-- ===================================================================== security
CREATE TABLE IF NOT EXISTS roles (
  id SERIAL PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  permissions TEXT NOT NULL,            -- JSON array of permission keys, ["*"] = everything
  is_system SMALLINT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS employees (
  id SERIAL PRIMARY KEY,
  emp_no VARCHAR(32) NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  position VARCHAR(80) NULL,
  department VARCHAR(80) NULL,
  phone VARCHAR(40) NULL,
  date_hired DATE NULL,
  active SMALLINT NOT NULL DEFAULT 1,
  notes TEXT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id SERIAL PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NULL,           -- manager PIN for POS overrides
  role_id INTEGER NULL REFERENCES roles(id) DEFERRABLE INITIALLY IMMEDIATE,
  employee_id INTEGER NULL REFERENCES employees(id) DEFERRABLE INITIALLY IMMEDIATE,
  active SMALLINT NOT NULL DEFAULT 1,
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id SERIAL PRIMARY KEY,
  attempt_key VARCHAR(190) NOT NULL,
  attempted_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_attempt ON login_attempts (attempt_key, attempted_at);

CREATE TABLE IF NOT EXISTS audit_log (
  id SERIAL PRIMARY KEY,
  ts TIMESTAMP NOT NULL,
  user_id INTEGER NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(60) NULL,
  entity_id INTEGER NULL,
  details TEXT NULL
);
CREATE INDEX IF NOT EXISTS ix_audit_ts ON audit_log (ts);

-- ===================================================================== finance
CREATE TABLE IF NOT EXISTS accounts (
  id SERIAL PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type VARCHAR(12) NOT NULL CHECK (type IN ('asset','liability','equity','income','expense')),
  subtype VARCHAR(30) NULL,             -- cash, bank, current, inventory, fixed, contra, cogs, opex ...
  system_key VARCHAR(40) NULL UNIQUE,   -- used by automatic postings, e.g. 'cash_on_hand'
  is_system SMALLINT NOT NULL DEFAULT 0,
  active SMALLINT NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS journal_entries (
  id SERIAL PRIMARY KEY,
  entry_no VARCHAR(32) NOT NULL UNIQUE,
  entry_date DATE NOT NULL,
  memo VARCHAR(255) NULL,
  source_type VARCHAR(30) NOT NULL DEFAULT 'manual',   -- manual, pos_sale, pos_return, inv_receive, petty_cash ...
  source_id INTEGER NULL,
  ref_no VARCHAR(60) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'posted' CHECK (status IN ('posted','void')),
  reversal_of INTEGER NULL,
  reversed_by INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS ix_je_date ON journal_entries (entry_date);
CREATE INDEX IF NOT EXISTS ix_je_source ON journal_entries (source_type, source_id);

CREATE TABLE IF NOT EXISTS journal_lines (
  id SERIAL PRIMARY KEY,
  entry_id INTEGER NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE,
  account_id INTEGER NOT NULL REFERENCES accounts(id) DEFERRABLE INITIALLY IMMEDIATE,
  debit NUMERIC(14,2) NOT NULL DEFAULT 0,
  credit NUMERIC(14,2) NOT NULL DEFAULT 0,
  memo VARCHAR(255) NULL,
  bank_account_id INTEGER NULL,
  party_type VARCHAR(20) NULL,          -- supplier, customer, employee
  party_id INTEGER NULL
);
CREATE INDEX IF NOT EXISTS ix_jl_account ON journal_lines (account_id);
CREATE INDEX IF NOT EXISTS ix_jl_entry ON journal_lines (entry_id);

CREATE TABLE IF NOT EXISTS bank_accounts (
  id SERIAL PRIMARY KEY,
  bank_name VARCHAR(80) NOT NULL,
  account_name VARCHAR(120) NULL,
  account_no VARCHAR(60) NULL,
  account_type VARCHAR(30) NULL,
  gl_account_id INTEGER NOT NULL REFERENCES accounts(id) DEFERRABLE INITIALLY IMMEDIATE,  -- each bank has its own "Cash in Bank - X" account
  active SMALLINT NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS bank_txns (
  id SERIAL PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  bank_account_id INTEGER NOT NULL REFERENCES bank_accounts(id) DEFERRABLE INITIALLY IMMEDIATE,
  txn_date DATE NOT NULL,
  txn_type VARCHAR(12) NOT NULL CHECK (txn_type IN ('deposit','withdrawal','transfer')),
  amount NUMERIC(14,2) NOT NULL,
  bank_charges NUMERIC(14,2) NOT NULL DEFAULT 0,
  counter_account_id INTEGER NULL,
  transfer_bank_id INTEGER NULL,
  reference VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'posted' CHECK (status IN ('posted','void')),
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS ix_bt_date ON bank_txns (txn_date);

CREATE TABLE IF NOT EXISTS suppliers (
  id SERIAL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  tin VARCHAR(30) NULL,
  terms_days INTEGER NOT NULL DEFAULT 0,
  active SMALLINT NOT NULL DEFAULT 1,
  notes TEXT NULL
);

CREATE TABLE IF NOT EXISTS customers (
  id SERIAL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  tin VARCHAR(30) NULL,
  credit_limit NUMERIC(14,2) NOT NULL DEFAULT 0,
  terms_days INTEGER NOT NULL DEFAULT 30,
  active SMALLINT NOT NULL DEFAULT 1,
  notes TEXT NULL
);

CREATE TABLE IF NOT EXISTS ap_bills (
  id SERIAL PRIMARY KEY,
  bill_no VARCHAR(32) NULL,
  supplier_id INTEGER NOT NULL REFERENCES suppliers(id) DEFERRABLE INITIALLY IMMEDIATE,
  bill_date DATE NOT NULL,
  due_date DATE NULL,
  ref_no VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  amount NUMERIC(14,2) NOT NULL,
  paid_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','partial','paid','void')),
  expense_account_id INTEGER NULL,
  source_type VARCHAR(30) NULL,         -- manual | inv_receive
  source_id INTEGER NULL,
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS ap_payments (
  id SERIAL PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  bill_id INTEGER NOT NULL REFERENCES ap_bills(id) DEFERRABLE INITIALLY IMMEDIATE,
  pay_date DATE NOT NULL,
  amount NUMERIC(14,2) NOT NULL,
  method VARCHAR(20) NOT NULL,          -- cash | petty_cash | bank
  bank_account_id INTEGER NULL,
  reference VARCHAR(60) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'posted' CHECK (status IN ('posted','void')),
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS ar_invoices (
  id SERIAL PRIMARY KEY,
  invoice_no VARCHAR(32) NULL,
  customer_id INTEGER NOT NULL REFERENCES customers(id) DEFERRABLE INITIALLY IMMEDIATE,
  inv_date DATE NOT NULL,
  due_date DATE NULL,
  ref_no VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  amount NUMERIC(14,2) NOT NULL,
  paid_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','partial','paid','void')),
  income_account_id INTEGER NULL,
  source_type VARCHAR(30) NULL,         -- manual | pos_sale
  source_id INTEGER NULL,
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS ar_receipts (
  id SERIAL PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  invoice_id INTEGER NOT NULL REFERENCES ar_invoices(id) DEFERRABLE INITIALLY IMMEDIATE,
  rcpt_date DATE NOT NULL,
  amount NUMERIC(14,2) NOT NULL,
  method VARCHAR(20) NOT NULL,
  bank_account_id INTEGER NULL,
  reference VARCHAR(60) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'posted' CHECK (status IN ('posted','void')),
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS cash_advances (
  id SERIAL PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  employee_id INTEGER NOT NULL REFERENCES employees(id) DEFERRABLE INITIALLY IMMEDIATE,
  request_date DATE NOT NULL,
  amount NUMERIC(14,2) NOT NULL,
  reason VARCHAR(255) NULL,
  repayment_terms VARCHAR(255) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','settled','cancelled')),
  requested_by INTEGER NULL,
  approved_by INTEGER NULL,
  approved_at TIMESTAMP NULL,
  release_method VARCHAR(20) NULL,      -- cash | petty_cash | bank
  bank_account_id INTEGER NULL,
  release_date DATE NULL,
  balance NUMERIC(14,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255) NULL,
  journal_entry_id INTEGER NULL,
  created_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS ca_repayments (
  id SERIAL PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  advance_id INTEGER NOT NULL REFERENCES cash_advances(id) DEFERRABLE INITIALLY IMMEDIATE,
  pay_date DATE NOT NULL,
  amount NUMERIC(14,2) NOT NULL,
  method VARCHAR(20) NOT NULL,          -- payroll | cash | bank
  bank_account_id INTEGER NULL,
  reference VARCHAR(60) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'posted' CHECK (status IN ('posted','void')),
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);

-- ===================================================================== inventory
CREATE TABLE IF NOT EXISTS uoms (
  id SERIAL PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  abbr VARCHAR(12) NOT NULL UNIQUE
);

-- Global conversions: 1 [from] = factor [to]   e.g. 1 kg = 1000 g
CREATE TABLE IF NOT EXISTS uom_conversions (
  id SERIAL PRIMARY KEY,
  from_uom_id INTEGER NOT NULL REFERENCES uoms(id) DEFERRABLE INITIALLY IMMEDIATE,
  to_uom_id INTEGER NOT NULL REFERENCES uoms(id) DEFERRABLE INITIALLY IMMEDIATE,
  factor NUMERIC(18,6) NOT NULL,
  UNIQUE (from_uom_id, to_uom_id)
);

CREATE TABLE IF NOT EXISTS categories (
  id SERIAL PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  color VARCHAR(9) NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active SMALLINT NOT NULL DEFAULT 1
);

-- item_type: product (stocked goods) | service (sold, not stocked: delivery fee, repair service, blood pressure check ...)
CREATE TABLE IF NOT EXISTS items (
  id SERIAL PRIMARY KEY,
  sku VARCHAR(40) NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  generic_name VARCHAR(160) NULL,                  -- generic / alternate name (medicines: generic name)
  brand VARCHAR(80) NULL,
  category_id INTEGER NULL REFERENCES categories(id) DEFERRABLE INITIALLY IMMEDIATE,
  item_type VARCHAR(12) NOT NULL CHECK (item_type IN ('product','service')),
  base_uom_id INTEGER NULL REFERENCES uoms(id) DEFERRABLE INITIALLY IMMEDIATE,
  price NUMERIC(14,2) NOT NULL DEFAULT 0,          -- selling price per base unit, VAT-inclusive
  wholesale_price NUMERIC(14,2) NULL,              -- price per base unit from wholesale_min_qty up
  wholesale_min_qty NUMERIC(14,4) NULL,
  avg_cost NUMERIC(14,4) NOT NULL DEFAULT 0,       -- moving average cost per base unit
  last_cost NUMERIC(14,4) NOT NULL DEFAULT 0,
  stock_qty NUMERIC(14,4) NOT NULL DEFAULT 0,      -- in base unit
  reorder_point NUMERIC(14,4) NOT NULL DEFAULT 0,
  reorder_qty NUMERIC(14,4) NOT NULL DEFAULT 0,
  sellable SMALLINT NOT NULL DEFAULT 1,
  active SMALLINT NOT NULL DEFAULT 1,
  vat_exempt SMALLINT NOT NULL DEFAULT 0,          -- VAT-exempt goods (e.g. maintenance medicines under RA 10963)
  rx_required SMALLINT NOT NULL DEFAULT 0,         -- prescription medicine
  track_batches SMALLINT NOT NULL DEFAULT 0,       -- lot / expiry tracking (FEFO)
  color VARCHAR(9) NULL,
  barcode VARCHAR(60) NULL,
  plu VARCHAR(10) NULL,                            -- scale PLU code (weighed goods)
  description VARCHAR(255) NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS ix_items_cat ON items (category_id);
CREATE INDEX IF NOT EXISTS ix_items_barcode ON items (barcode);
CREATE INDEX IF NOT EXISTS ix_items_plu ON items (plu);

-- Item-specific units: 1 [uom] = factor [base unit]   e.g. 1 box = 100 tab, 1 case = 12 btl.
-- A unit with a price is sold as a pack on the POS; a unit barcode lets the scanner pick the pack.
CREATE TABLE IF NOT EXISTS item_uoms (
  id SERIAL PRIMARY KEY,
  item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE,
  uom_id INTEGER NOT NULL REFERENCES uoms(id) DEFERRABLE INITIALLY IMMEDIATE,
  factor NUMERIC(18,6) NOT NULL,
  price NUMERIC(14,2) NULL,
  barcode VARCHAR(60) NULL,
  UNIQUE (item_id, uom_id)
);
CREATE INDEX IF NOT EXISTS ix_item_uoms_barcode ON item_uoms (barcode);

-- Lots / batches with expiry dates (items with track_batches = 1). Sales deduct first-expiry-first-out.
CREATE TABLE IF NOT EXISTS item_batches (
  id SERIAL PRIMARY KEY,
  item_id INTEGER NOT NULL REFERENCES items(id) DEFERRABLE INITIALLY IMMEDIATE,
  batch_no VARCHAR(60) NULL,
  expiry_date DATE NULL,
  qty_in NUMERIC(14,4) NOT NULL DEFAULT 0,
  qty_on_hand NUMERIC(14,4) NOT NULL DEFAULT 0,
  unit_cost NUMERIC(14,4) NOT NULL DEFAULT 0,
  supplier_id INTEGER NULL,
  doc_id INTEGER NULL,                  -- receiving document that created it
  received_at TIMESTAMP NULL,
  notes VARCHAR(255) NULL
);
CREATE INDEX IF NOT EXISTS ix_batches_item ON item_batches (item_id, expiry_date);

-- Which batch each movement touched (so voids put stock back into the same lots)
CREATE TABLE IF NOT EXISTS batch_movements (
  id SERIAL PRIMARY KEY,
  batch_id INTEGER NULL REFERENCES item_batches(id) DEFERRABLE INITIALLY IMMEDIATE,
  item_id INTEGER NOT NULL,
  qty NUMERIC(14,4) NOT NULL,
  mtype VARCHAR(20) NOT NULL,
  ref_type VARCHAR(20) NULL,
  ref_id INTEGER NULL,
  ts TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_bm_ref ON batch_movements (ref_type, ref_id);

-- Stock ledger: every change to stock_qty is recorded here (signed qty in base unit)
CREATE TABLE IF NOT EXISTS stock_movements (
  id SERIAL PRIMARY KEY,
  item_id INTEGER NOT NULL REFERENCES items(id) DEFERRABLE INITIALLY IMMEDIATE,
  ts TIMESTAMP NOT NULL,
  bdate DATE NOT NULL,                  -- business date
  mtype VARCHAR(20) NOT NULL,           -- RECEIVE, SALE, RETURN, ISSUE, WASTE, COUNT, *_VOID
  qty NUMERIC(14,4) NOT NULL,
  unit_cost NUMERIC(14,4) NOT NULL DEFAULT 0,
  total_cost NUMERIC(14,2) NOT NULL DEFAULT 0,
  balance_after NUMERIC(14,4) NULL,
  ref_type VARCHAR(20) NULL,
  ref_id INTEGER NULL,
  ref_no VARCHAR(40) NULL,
  notes VARCHAR(255) NULL,
  user_id INTEGER NULL
);
CREATE INDEX IF NOT EXISTS ix_sm_item ON stock_movements (item_id, bdate);
CREATE INDEX IF NOT EXISTS ix_sm_date ON stock_movements (bdate, mtype);
CREATE INDEX IF NOT EXISTS ix_sm_ref ON stock_movements (ref_type, ref_id);

-- RECEIVE = purchase / stock in, ISSUE = stock issuance (internal use), WASTE = damaged, expired & write-offs
CREATE TABLE IF NOT EXISTS inv_docs (
  id SERIAL PRIMARY KEY,
  doc_type VARCHAR(10) NOT NULL CHECK (doc_type IN ('RECEIVE','ISSUE','WASTE')),
  doc_no VARCHAR(32) NULL,
  doc_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted','cancelled')),
  supplier_id INTEGER NULL REFERENCES suppliers(id) DEFERRABLE INITIALLY IMMEDIATE,
  invoice_no VARCHAR(60) NULL,
  payment_mode VARCHAR(20) NULL,        -- credit | cash | petty_cash | bank | opening
  bank_account_id INTEGER NULL,
  vat_inclusive SMALLINT NOT NULL DEFAULT 0,
  reason VARCHAR(120) NULL,
  issued_to VARCHAR(120) NULL,
  expense_account_id INTEGER NULL,
  notes TEXT NULL,
  total_cost NUMERIC(14,2) NOT NULL DEFAULT 0,
  journal_entry_id INTEGER NULL,
  ap_bill_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL,
  posted_by INTEGER NULL,
  posted_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS ix_inv_docs ON inv_docs (doc_type, doc_date);

CREATE TABLE IF NOT EXISTS inv_doc_lines (
  id SERIAL PRIMARY KEY,
  doc_id INTEGER NOT NULL REFERENCES inv_docs(id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE,
  item_id INTEGER NOT NULL REFERENCES items(id) DEFERRABLE INITIALLY IMMEDIATE,
  qty NUMERIC(14,4) NOT NULL,
  uom_id INTEGER NULL,
  base_qty NUMERIC(14,4) NOT NULL DEFAULT 0,
  unit_cost NUMERIC(14,4) NOT NULL DEFAULT 0,     -- per unit entered
  line_total NUMERIC(14,2) NOT NULL DEFAULT 0,
  batch_no VARCHAR(60) NULL,            -- RECEIVE: lot number of the goods received
  expiry_date DATE NULL,                -- RECEIVE: expiry of that lot
  batch_id INTEGER NULL,                -- ISSUE / WASTE: take from this lot (blank = first expiry first)
  reason VARCHAR(120) NULL,
  notes VARCHAR(255) NULL
);
CREATE INDEX IF NOT EXISTS ix_dl_doc ON inv_doc_lines (doc_id);

CREATE TABLE IF NOT EXISTS count_sessions (
  id SERIAL PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  count_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','posted','cancelled')),
  category_id INTEGER NULL,
  notes TEXT NULL,
  total_variance_value NUMERIC(14,2) NOT NULL DEFAULT 0,
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL,
  posted_by INTEGER NULL,
  posted_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS count_lines (
  id SERIAL PRIMARY KEY,
  session_id INTEGER NOT NULL REFERENCES count_sessions(id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE,
  item_id INTEGER NOT NULL REFERENCES items(id) DEFERRABLE INITIALLY IMMEDIATE,
  system_qty NUMERIC(14,4) NOT NULL DEFAULT 0,
  counted_qty NUMERIC(14,4) NULL,       -- NULL = not counted (not adjusted)
  variance NUMERIC(14,4) NULL,
  unit_cost NUMERIC(14,4) NOT NULL DEFAULT 0,
  variance_value NUMERIC(14,2) NULL,
  UNIQUE (session_id, item_id)
);

-- ===================================================================== POS
-- Discount presets picked at the POS (value NULL = cashier enters the value)
CREATE TABLE IF NOT EXISTS discounts (
  id SERIAL PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  kind VARCHAR(10) NOT NULL CHECK (kind IN ('sc','pwd','percent','amount')),
  value NUMERIC(14,2) NULL,
  scope VARCHAR(10) NOT NULL DEFAULT 'both' CHECK (scope IN ('both','order','item')),
  requires_approval SMALLINT NOT NULL DEFAULT 1,
  active SMALLINT NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0
);

-- One business day = one cash session (beginning cash -> end of day count)
CREATE TABLE IF NOT EXISTS cash_sessions (
  id SERIAL PRIMARY KEY,
  business_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
  opened_by INTEGER NULL,
  opened_at TIMESTAMP NULL,
  opening_cash NUMERIC(14,2) NOT NULL DEFAULT 0,
  closed_by INTEGER NULL,
  closed_at TIMESTAMP NULL,
  expected_cash NUMERIC(14,2) NULL,
  counted_cash NUMERIC(14,2) NULL,
  variance NUMERIC(14,2) NULL,
  denominations TEXT NULL,              -- JSON {"opening": {...}, "closing": {...}}
  notes VARCHAR(255) NULL,
  z_data TEXT NULL,                     -- JSON snapshot of the Z-reading
  journal_entry_id INTEGER NULL
);

-- A ticket is a sale. Open tickets are "held" sales waiting to be resumed; paid tickets carry the receipt number.
CREATE TABLE IF NOT EXISTS tickets (
  id SERIAL PRIMARY KEY,
  ticket_no VARCHAR(32) NULL,
  receipt_no VARCHAR(32) NULL,          -- assigned when paid
  cash_session_id INTEGER NULL,
  business_date DATE NOT NULL,
  customer_name VARCHAR(120) NULL,
  customer_id INTEGER NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','paid','void')),
  subtotal NUMERIC(14,2) NOT NULL DEFAULT 0,
  discount_type VARCHAR(10) NOT NULL DEFAULT 'none' CHECK (discount_type IN ('none','sc','pwd','percent','amount')),   -- whole-receipt discount
  discount_id INTEGER NULL,             -- preset used (discounts table)
  discount_name VARCHAR(60) NULL,
  discount_rate NUMERIC(14,2) NOT NULL DEFAULT 0,
  discount_amount NUMERIC(14,2) NOT NULL DEFAULT 0,   -- all discounts on the receipt (order + items)
  sc_discount NUMERIC(14,2) NOT NULL DEFAULT 0,       -- part that is SC/PWD (computed on net of VAT)
  promo_discount NUMERIC(14,2) NOT NULL DEFAULT 0,    -- part that is promo / employee / fixed (VAT-inclusive)
  sc_count INTEGER NOT NULL DEFAULT 0,
  sc_details TEXT NULL,                 -- JSON [{name, id_no}]
  vatable_sales NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat_exempt_sales NUMERIC(14,2) NOT NULL DEFAULT 0,
  total NUMERIC(14,2) NOT NULL DEFAULT 0,
  paid_total NUMERIC(14,2) NOT NULL DEFAULT 0,
  change_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  cogs NUMERIC(14,2) NOT NULL DEFAULT 0,
  refunded_total NUMERIC(14,2) NOT NULL DEFAULT 0,    -- refunds given through sales returns
  rx_note VARCHAR(255) NULL,            -- prescription / doctor reference (pharmacy)
  notes VARCHAR(255) NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL,
  paid_by INTEGER NULL,
  paid_at TIMESTAMP NULL,
  voided_by INTEGER NULL,
  voided_at TIMESTAMP NULL,
  void_reason VARCHAR(255) NULL,
  void_session_id INTEGER NULL,
  journal_entry_id INTEGER NULL
);
CREATE INDEX IF NOT EXISTS ix_tickets_bdate ON tickets (business_date, status);
CREATE INDEX IF NOT EXISTS ix_tickets_session ON tickets (cash_session_id);
CREATE INDEX IF NOT EXISTS ix_tickets_receipt ON tickets (receipt_no);

CREATE TABLE IF NOT EXISTS ticket_items (
  id SERIAL PRIMARY KEY,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE,
  item_id INTEGER NOT NULL REFERENCES items(id) DEFERRABLE INITIALLY IMMEDIATE,
  name VARCHAR(160) NOT NULL,
  qty NUMERIC(14,4) NOT NULL,           -- in the unit sold (uom_id)
  uom_id INTEGER NULL,
  uom_label VARCHAR(12) NULL,
  factor NUMERIC(18,6) NOT NULL DEFAULT 1,   -- base units per 1 sold unit
  price NUMERIC(14,2) NOT NULL,         -- per sold unit
  line_total NUMERIC(14,2) NOT NULL,
  unit_cost NUMERIC(14,4) NOT NULL DEFAULT 0,  -- cost per base unit at the time of sale
  vat_exempt SMALLINT NOT NULL DEFAULT 0,
  discount_id INTEGER NULL,             -- per-item discount (preset)
  discount_name VARCHAR(60) NULL,
  discount_kind VARCHAR(10) NULL CHECK (discount_kind IN ('sc','pwd','percent','amount')),
  discount_value NUMERIC(14,2) NOT NULL DEFAULT 0,
  discount_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  returned_qty NUMERIC(14,4) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','void')),
  void_reason VARCHAR(255) NULL,
  voided_by INTEGER NULL,
  voided_at TIMESTAMP NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS ix_ti_ticket ON ticket_items (ticket_id);

CREATE TABLE IF NOT EXISTS payments (
  id SERIAL PRIMARY KEY,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE,
  cash_session_id INTEGER NULL,
  method VARCHAR(20) NOT NULL,          -- cash, card, gcash, maya, bank_transfer, charge
  amount NUMERIC(14,2) NOT NULL,        -- amount applied to the bill (cash = tendered - change)
  tendered NUMERIC(14,2) NULL,
  change_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  reference VARCHAR(60) NULL,
  customer_id INTEGER NULL,
  user_id INTEGER NULL,
  created_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS ix_pay_ticket ON payments (ticket_id);

-- Customer returns against a paid receipt: refund (cash / e-wallet / card reversal) and restock or write-off
CREATE TABLE IF NOT EXISTS sales_returns (
  id SERIAL PRIMARY KEY,
  return_no VARCHAR(32) NULL,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) DEFERRABLE INITIALLY IMMEDIATE,
  cash_session_id INTEGER NULL,
  business_date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  refund_method VARCHAR(20) NOT NULL,   -- cash, card, gcash, maya, bank_transfer
  refund_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat_exempt NUMERIC(14,2) NOT NULL DEFAULT 0,
  cogs NUMERIC(14,2) NOT NULL DEFAULT 0,
  restock SMALLINT NOT NULL DEFAULT 1,  -- 1 = goods back to stock, 0 = damaged (written off)
  status VARCHAR(10) NOT NULL DEFAULT 'posted' CHECK (status IN ('posted','void')),
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS ix_returns_ticket ON sales_returns (ticket_id);
CREATE INDEX IF NOT EXISTS ix_returns_session ON sales_returns (cash_session_id);

CREATE TABLE IF NOT EXISTS sales_return_lines (
  id SERIAL PRIMARY KEY,
  return_id INTEGER NOT NULL REFERENCES sales_returns(id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE,
  ticket_item_id INTEGER NOT NULL,
  item_id INTEGER NOT NULL,
  name VARCHAR(160) NOT NULL,
  qty NUMERIC(14,4) NOT NULL,           -- in the unit sold
  factor NUMERIC(18,6) NOT NULL DEFAULT 1,
  price NUMERIC(14,2) NOT NULL,
  amount NUMERIC(14,2) NOT NULL,        -- refunded for this line (net of its discounts)
  vat_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  cogs NUMERIC(14,2) NOT NULL DEFAULT 0
);

-- ===================================================================== petty cash
-- expense: money paid out; replenish: money put into the petty cash fund.
-- source: fund = petty cash box, drawer = POS cash drawer (reduces expected cash at end of day)
CREATE TABLE IF NOT EXISTS petty_cash_txns (
  id SERIAL PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  txn_date DATE NOT NULL,
  txn_type VARCHAR(10) NOT NULL CHECK (txn_type IN ('expense','replenish')),
  source VARCHAR(10) NOT NULL DEFAULT 'fund' CHECK (source IN ('fund','drawer')),
  amount NUMERIC(14,2) NOT NULL,
  account_id INTEGER NULL,
  bank_account_id INTEGER NULL,
  payee VARCHAR(120) NULL,
  description VARCHAR(255) NULL,
  or_no VARCHAR(60) NULL,
  cash_session_id INTEGER NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'posted' CHECK (status IN ('posted','void')),
  journal_entry_id INTEGER NULL,
  created_by INTEGER NULL,
  created_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS ix_pc_date ON petty_cash_txns (txn_date);
