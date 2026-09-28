-- Migration: 016 - Change Request multi-PIC (many-to-many)
-- Kandidat PIC: user aktif role admin/teknisi (divalidasi di aplikasi, mirror report_for).
-- Kelola (tambah/hapus): hanya admin via canManageCrPic().
-- assigned_to lama DIPERTAHANKAN dan disinkron = PIC tertua agar laporan/API lama tetap jalan.
-- Aman dijalankan berulang (IF NOT EXISTS / ON CONFLICT DO NOTHING).

CREATE TABLE IF NOT EXISTS change_request_pics (
    cr_id INTEGER NOT NULL REFERENCES change_requests(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    assigned_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cr_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_crpics_cr ON change_request_pics(cr_id);
CREATE INDEX IF NOT EXISTS idx_crpics_user ON change_request_pics(user_id);

-- Backfill: CR lama yang punya assigned_to tunggal langsung muncul sebagai 1 PIC.
INSERT INTO change_request_pics (cr_id, user_id)
SELECT id, assigned_to FROM change_requests WHERE assigned_to IS NOT NULL
ON CONFLICT DO NOTHING;
