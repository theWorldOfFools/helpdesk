-- Migration: 011 - Change Request header foundation
-- Acuan: Task 36e60b11 (wireframe 2 kolom, mapping field). Fondasi header saja;
-- tabel rincian dinamis detail + view/edit dikerjakan Task berikutnya.
-- Aman dijalankan berurutan 001..011 dari schema kosong (IF NOT EXISTS).

-- 1) Header Change Request (tir u pola tickets: status/priority/assigned_to/sla_due_at + attachment)
CREATE TABLE IF NOT EXISTS change_requests (
    id SERIAL PRIMARY KEY,
    cr_number VARCHAR(20) UNIQUE NOT NULL,
    aplikasi VARCHAR(150) NOT NULL,
    unit VARCHAR(100) NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    waktu_dibutuhkan TIMESTAMP NULL,
    modul VARCHAR(150) NOT NULL,
    fitur VARCHAR(255) NOT NULL,
    url VARCHAR(500) NULL,
    keterangan TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    priority VARCHAR(20) NOT NULL DEFAULT 'medium',
    assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
    sla_due_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    attachment_path VARCHAR(255) NULL,
    attachment_original VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cr_number ON change_requests(cr_number);
CREATE INDEX IF NOT EXISTS idx_cr_status ON change_requests(status);
CREATE INDEX IF NOT EXISTS idx_cr_priority ON change_requests(priority);
CREATE INDEX IF NOT EXISTS idx_cr_user ON change_requests(user_id);
CREATE INDEX IF NOT EXISTS idx_cr_assigned ON change_requests(assigned_to);
CREATE INDEX IF NOT EXISTS idx_cr_sla_due ON change_requests(sla_due_at);
CREATE INDEX IF NOT EXISTS idx_cr_created ON change_requests(created_at);
CREATE INDEX IF NOT EXISTS idx_cr_aplikasi ON change_requests(aplikasi);

-- 2) Rincian item (fondasi; baris dinamis multi-jenis dikerjakan Task berikutnya)
-- jenis dibatasi sesuai form: Penambahan / Perubahan / Design
CREATE TABLE IF NOT EXISTS change_request_items (
    id SERIAL PRIMARY KEY,
    cr_id INTEGER NOT NULL REFERENCES change_requests(id) ON DELETE CASCADE,
    jenis VARCHAR(20) NOT NULL CHECK (jenis IN ('Penambahan', 'Perubahan', 'Design')),
    uraian TEXT NOT NULL,
    alasan TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cri_cr ON change_request_items(cr_id);
CREATE INDEX IF NOT EXISTS idx_cri_jenis ON change_request_items(jenis);

-- 3) Riwayat / audit (mirror ticket_history, minimal note + actor)
CREATE TABLE IF NOT EXISTS change_request_history (
    id SERIAL PRIMARY KEY,
    cr_id INTEGER NOT NULL REFERENCES change_requests(id) ON DELETE CASCADE,
    actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_crh_cr ON change_request_history(cr_id);
CREATE INDEX IF NOT EXISTS idx_crh_created ON change_request_history(created_at);
