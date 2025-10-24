-- Payment methods saved by users (PAN is NOT stored, only masked and tokenized for demo)
CREATE TABLE IF NOT EXISTS payment_methods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  brand TEXT NOT NULL,              -- Visa/Mastercard
  masked TEXT NOT NULL,             -- **** **** **** 1234
  token TEXT NOT NULL,              -- demo token, not real PAN
  holder_name TEXT,
  exp_month INTEGER,
  exp_year INTEGER,
  created_at TEXT NOT NULL,
  UNIQUE(user_id, token),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Wallet transactions (topups and refunds)
CREATE TABLE IF NOT EXISTS wallet_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  type TEXT NOT NULL CHECK(type IN ('topup','refund','charge')),
  amount_cents INTEGER NOT NULL,
  meta TEXT,                        -- json string (coupon, ticket_id, etc.)
  created_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);


