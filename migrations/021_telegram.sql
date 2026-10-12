-- Migration: 021 - Telegram bot (notifikasi + info/laporan, menu global dinamis, linking teknisi)
-- Idempoten (IF NOT EXISTS), aman dijalankan ulang.
-- Desain: 1 bot token, N group (allowlist), 1 menu tree global disaring per-role.

-- 1) Menu global dinamis (tree parent_id). min_role: guest/pelapor/teknisi/admin.
CREATE TABLE IF NOT EXISTS telegram_menus (
    id SERIAL PRIMARY KEY,
    parent_id INTEGER NULL REFERENCES telegram_menus(id) ON DELETE CASCADE,
    label VARCHAR(80) NOT NULL,
    command VARCHAR(40) NOT NULL DEFAULT '',
    action VARCHAR(40) NOT NULL DEFAULT 'menu',
    payload TEXT NULL,
    min_role VARCHAR(20) NOT NULL DEFAULT 'guest',
    sort INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_menus_parent ON telegram_menus(parent_id, sort, id);
CREATE INDEX IF NOT EXISTS idx_tg_menus_active ON telegram_menus(is_active);

-- Seed menu global awal (root parent_id NULL). ON CONFLICT DO NOTHING via guard command unik.
INSERT INTO telegram_menus (label, command, action, payload, min_role, sort) VALUES
    ('🎫 Tiket Open', '/tiket_open', 'tiket_open', NULL, 'guest', 10),
    ('📋 CR Aktif', '/cr_aktif', 'cr_aktif', NULL, 'guest', 20),
    ('🗂 Tiket Saya', '/tiket_saya', 'tiket_saya', NULL, 'pelapor', 30),
    ('🧑‍💻 Antrean Divisi', '/antrean', 'antrean', NULL, 'teknisi', 40),
    ('📞 Oncall Hari Ini', '/oncall', 'oncall_today', NULL, 'guest', 50),
    ('📊 Laporan Harian', '/laporan', 'report_daily', NULL, 'teknisi', 60),
    ('🔍 Cari KB', '/kb', 'kb_hint', NULL, 'guest', 70),
    ('❓ Bantuan', '/help', 'help', NULL, 'guest', 80)
ON CONFLICT DO NOTHING;

-- 2) Allowlist group/private chat yang boleh dilayani bot.
CREATE TABLE IF NOT EXISTS telegram_chats (
    chat_id BIGINT PRIMARY KEY,
    title VARCHAR(255) NULL,
    chat_type VARCHAR(20) NULL DEFAULT 'group',
    is_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3) Link Telegram <-> user helpdesk. telegram_id stabil (1 telegram = 1 user).
CREATE TABLE IF NOT EXISTS telegram_identities (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    telegram_id BIGINT UNIQUE NOT NULL,
    telegram_username VARCHAR(100) NULL,
    linked_via VARCHAR(10) NOT NULL DEFAULT 'otp',
    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_ident_tgid ON telegram_identities(telegram_id);
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tg_ident_via_check') THEN
        ALTER TABLE telegram_identities ADD CONSTRAINT tg_ident_via_check CHECK (linked_via IN ('otp','manual'));
    END IF;
END
$$;

-- 4) Kode OTP sekali pakai (simpan hash, bukan plain). Expired 10 menit, max 5x salah.
CREATE TABLE IF NOT EXISTS telegram_link_codes (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_codes_user ON telegram_link_codes(user_id, used_at, expires_at);

-- 5) Log webhook untuk audit + debug.
CREATE TABLE IF NOT EXISTS telegram_logs (
    id SERIAL PRIMARY KEY,
    chat_id BIGINT NULL,
    telegram_id BIGINT NULL,
    username VARCHAR(100) NULL,
    command VARCHAR(100) NULL,
    payload TEXT NULL,
    resp_ok BOOLEAN NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_logs_created ON telegram_logs(created_at);

-- 6) Default config via tabel settings (token asli tetap di .env TELEGRAM_BOT_TOKEN).
INSERT INTO settings (kunci, nilai, keterangan) VALUES
    ('telegram_enabled', '0', '1 = bot aktif kirim notifikasi'),
    ('telegram_default_chat_id', '', 'chat_id group utama untuk broadcast notifikasi'),
    ('telegram_bot_username', '', 'username bot tanpa @ (untuk link t.me)'),
    ('telegram_daily_report_at', '07:00', 'Jam kirim laporan harian (cron)')
ON CONFLICT (kunci) DO NOTHING;
