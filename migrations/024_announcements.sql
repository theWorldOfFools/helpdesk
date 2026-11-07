-- Migration: 024 - Pengumuman global (dialog setelah login)
-- Idempoten (IF NOT EXISTS), aman dijalankan ulang di database lama.
-- Hak:
--   - Buat/kelola pengumuman: admin.
--   - Dialog tampil untuk SEMUA user yang login.
--   - User dapat menutup dialog; pengumuman tidak tampil lagi selama 24 jam
--     untuk user tersebut (announcement_dismissals).

CREATE TABLE IF NOT EXISTS announcements (
    id SERIAL PRIMARY KEY,
    judul VARCHAR(255) NOT NULL,
    isi TEXT NOT NULL DEFAULT '',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    ends_at TIMESTAMP,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_announcements_active
    ON announcements(is_active, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_announcements_ends
    ON announcements(ends_at);

CREATE TABLE IF NOT EXISTS announcement_dismissals (
    id SERIAL PRIMARY KEY,
    announcement_id INTEGER NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    dismissed_until TIMESTAMP NOT NULL,
    dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (announcement_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_announcement_dismissals_user
    ON announcement_dismissals(user_id, dismissed_until);
CREATE INDEX IF NOT EXISTS idx_announcement_dismissals_announcement
    ON announcement_dismissals(announcement_id);
