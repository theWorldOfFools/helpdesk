-- Migration: 014 - Master Divisi
-- Daftar divisi terpusat (sebelumnya hardcode di create/edit_ticket.php + create/edit_cr.php).
-- Form mengambil daftar aktif via getActiveDivisions(); rename divisi dipropagasi
-- ke tickets.division + users.division oleh divisi.php. Idempoten, aman dijalankan ulang.

CREATE TABLE IF NOT EXISTS divisions (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed bawaan (daftar lama yang hardcode)
INSERT INTO divisions (name) VALUES
    ('IT Infrastructure'),
    ('IT Development'),
    ('IT Support'),
    ('IT Security'),
    ('Network'),
    ('System Administration')
ON CONFLICT (name) DO NOTHING;

-- Serap nilai yang sudah terlanjur dipakai di data existing (tiket + pengguna)
INSERT INTO divisions (name)
SELECT DISTINCT division FROM tickets WHERE division IS NOT NULL AND btrim(division) <> ''
ON CONFLICT (name) DO NOTHING;

INSERT INTO divisions (name)
SELECT DISTINCT division FROM users WHERE division IS NOT NULL AND btrim(division) <> ''
ON CONFLICT (name) DO NOTHING;
