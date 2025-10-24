-- Add non-guessable public token (PNR) to tickets for secure access
ALTER TABLE tickets ADD COLUMN pnr TEXT;

-- Backfill PNRs for existing rows
UPDATE tickets SET pnr = lower(hex(randomblob(8))) WHERE pnr IS NULL;

-- Ensure uniqueness
CREATE UNIQUE INDEX IF NOT EXISTS uq_tickets_pnr ON tickets(pnr);


