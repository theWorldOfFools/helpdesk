-- Migration: 003 - Ticket attachments
-- Description: Add attachment columns to tickets table

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255);
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS attachment_original VARCHAR(255);
