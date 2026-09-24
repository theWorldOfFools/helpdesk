-- Migration: 013 - Auth via SIMRS (PostgreSQL server sama, DB db_rswb_dummy)
-- Strategi: SIMRS jadi sumber verifikasi, tabel users lokal tetap cerminan (JIT provisioning).
-- Semua user SIMRS masuk sebagai 'pelapor'; promosi admin/teknisi manual via user_management.
-- Idempoten (IF NOT EXISTS / DO block), aman dijalankan ulang.

-- 1) Kolom sumber eksternal di users lokal
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_source VARCHAR(10) NOT NULL DEFAULT 'local';
ALTER TABLE users ADD COLUMN IF NOT EXISTS external_id INTEGER NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS jabatan VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS unit_kerja VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_sync_at TIMESTAMP NULL;

-- 2) Password lokal nullable: user SIMRS tidak punya password lokal (verifikasi live ke SIMRS)
ALTER TABLE users ALTER COLUMN password DROP NOT NULL;

-- 3) Batas nilai auth_source (guard via DO block agar idempoten)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_auth_source_check') THEN
        ALTER TABLE users ADD CONSTRAINT users_auth_source_check CHECK (auth_source IN ('local', 'simrs'));
    END IF;
END
$$;

-- 4) Unik per sumber eksternal: 1 baris lokal per loginpemakai_id SIMRS
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_external ON users(auth_source, external_id) WHERE external_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_users_auth_source ON users(auth_source);
