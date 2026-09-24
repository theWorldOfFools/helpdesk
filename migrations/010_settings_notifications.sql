-- Migration: 010 - Pengaturan target SLA (jam kerja) + notifikasi in-app
CREATE TABLE IF NOT EXISTS settings (
    kunci VARCHAR(50) PRIMARY KEY,
    nilai VARCHAR(255) NOT NULL,
    keterangan VARCHAR(255) NOT NULL DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO settings (kunci, nilai, keterangan) VALUES
    ('sla_critical_hours', '4', 'Target jam kerja prioritas critical'),
    ('sla_high_hours', '24', 'Target jam kerja prioritas high'),
    ('sla_medium_hours', '72', 'Target jam kerja prioritas medium'),
    ('sla_low_hours', '120', 'Target jam kerja prioritas low'),
    ('sla_response_minutes', '60', 'Batas respons pertama (menit kerja)'),
    ('sla_response_target', '100', 'Target kepatuhan respons (%)')
ON CONFLICT (kunci) DO NOTHING;

CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    judul VARCHAR(255) NOT NULL,
    isi TEXT,
    link VARCHAR(255),
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id, is_read, id DESC);
