-- Migration: 015 - Jadwal Oncall
-- 1 orang per tanggal; Minggu / tanggal merah (holidays) boleh 2 orang (utama + pendamping).
-- Format import standar: Nama | Nomor tanggal (1-31), bulan diambil dari konteks form.
-- Idempoten, aman dijalankan ulang.

CREATE TABLE IF NOT EXISTS oncall_schedules (
    id SERIAL PRIMARY KEY,
    tanggal DATE NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    peran VARCHAR(20) NOT NULL DEFAULT 'utama' CHECK (peran IN ('utama', 'pendamping')),
    sumber VARCHAR(20) NOT NULL DEFAULT 'manual' CHECK (sumber IN ('manual', 'import_xls', 'import_png', 'rotasi')),
    catatan VARCHAR(255) NULL,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tanggal, user_id)
);

CREATE INDEX IF NOT EXISTS idx_oncall_tanggal ON oncall_schedules(tanggal);
CREATE INDEX IF NOT EXISTS idx_oncall_user ON oncall_schedules(user_id);
CREATE INDEX IF NOT EXISTS idx_oncall_tanggal_peran ON oncall_schedules(tanggal, peran);
