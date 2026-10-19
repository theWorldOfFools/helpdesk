-- Migration: 022 - Basis Solusi: link bagikan publik (preview tanpa login)
-- Idempoten (IF NOT EXISTS), aman dijalankan ulang.
-- Satu token per artikel; permanen sampai dicabut (share_token di-NULL-kan saat cabut).

ALTER TABLE kb_articles ADD COLUMN IF NOT EXISTS share_token VARCHAR(64) NULL;
ALTER TABLE kb_articles ADD COLUMN IF NOT EXISTS share_created_at TIMESTAMP NULL;
ALTER TABLE kb_articles ADD COLUMN IF NOT EXISTS share_created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_kb_share_token ON kb_articles(share_token) WHERE share_token IS NOT NULL;
