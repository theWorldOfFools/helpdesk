-- Migration: 025 - Override divisi helpdesk untuk akun SIMRS
-- Akun SIMRS tetap memakai nama & password dari SIMRS, tetapi divisi helpdesk
-- (untuk routing tiket/CR) boleh disesuaikan sendiri lewat menu Profil.
-- Flag ini mencegah sinkronisasi login berikutnya menimpa pilihan tersebut.
-- Idempoten, aman dijalankan ulang.

ALTER TABLE users ADD COLUMN IF NOT EXISTS division_override BOOLEAN NOT NULL DEFAULT FALSE;

-- Backfill: akun SIMRS yang divisinya sudah berbeda dari unit_kerja dianggap override lokal.
UPDATE users
SET division_override = TRUE
WHERE auth_source = 'simrs'
  AND division IS DISTINCT FROM unit_kerja;
