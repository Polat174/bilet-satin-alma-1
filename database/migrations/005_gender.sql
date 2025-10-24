-- Add gender column to users table
ALTER TABLE users ADD COLUMN gender TEXT DEFAULT NULL;

-- Add gender column to tickets table for seat gender info
ALTER TABLE tickets ADD COLUMN passenger_gender TEXT DEFAULT NULL;
