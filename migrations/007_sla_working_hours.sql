-- Migration: 007 - SLA jam kerja (Senin-Sabtu 08:00-17:00, Minggu libur)
-- + tabel hari libur + fungsi working_minutes() untuk MTTR & SLA respons.

CREATE TABLE IF NOT EXISTS holidays (
    id SERIAL PRIMARY KEY,
    tanggal DATE UNIQUE NOT NULL,
    keterangan VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_holidays_tanggal ON holidays(tanggal);

-- Contoh awal (sesuaikan via menu Laporan > Hari Libur)
INSERT INTO holidays (tanggal, keterangan) VALUES
    ('2026-01-01', 'Tahun Baru'),
    ('2026-05-01', 'Hari Buruh'),
    ('2026-08-17', 'Hari Kemerdekaan'),
    ('2026-12-25', 'Hari Natal')
ON CONFLICT (tanggal) DO NOTHING;

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS sla_hours INTEGER;

-- Menit kerja antara dua timestamp (Senin-Sabtu 08:00-17:00, lewati Minggu & holidays).
-- Dipakai untuk: SLA respons 60 mnt, MTTR, umur breach.
CREATE OR REPLACE FUNCTION working_minutes(from_ts TIMESTAMP, to_ts TIMESTAMP)
RETURNS INTEGER AS $$
DECLARE
    cur TIMESTAMP;
    fin TIMESTAMP;
    total INTEGER := 0;
    day_start TIME := TIME '08:00';
    day_end TIME := TIME '17:00';
    ws TIMESTAMP;
    we TIMESTAMP;
BEGIN
    IF from_ts IS NULL OR to_ts IS NULL OR to_ts <= from_ts THEN
        RETURN 0;
    END IF;
    cur := date_trunc('day', from_ts);
    fin := to_ts;
    WHILE cur <= fin LOOP
        -- 0=Minggu .. 6=Sabtu ; lewati Minggu dan tanggal libur
        IF EXTRACT(DOW FROM cur) <> 0
           AND NOT EXISTS (SELECT 1 FROM holidays WHERE tanggal = cur::date) THEN
            ws := cur + day_start;
            we := cur + day_end;
            IF from_ts < we AND to_ts > ws THEN
                total := total + EXTRACT(EPOCH FROM (LEAST(to_ts, we) - GREATEST(from_ts, ws))) / 60;
            END IF;
        END IF;
        cur := cur + INTERVAL '1 day';
    END LOOP;
    RETURN total;
END;
$$ LANGUAGE plpgsql STABLE;

-- Geser timestamp ke awal jam kerja berikutnya bila di luar jam kerja / libur.
CREATE OR REPLACE FUNCTION next_working_start(ts TIMESTAMP)
RETURNS TIMESTAMP AS $$
DECLARE
    cur TIMESTAMP := ts;
BEGIN
    LOOP
        IF EXTRACT(DOW FROM cur) = 0
           OR EXISTS (SELECT 1 FROM holidays WHERE tanggal = cur::date) THEN
            cur := date_trunc('day', cur) + INTERVAL '1 day' + TIME '08:00';
            CONTINUE;
        END IF;
        IF cur::time < TIME '08:00' THEN
            cur := date_trunc('day', cur) + TIME '08:00';
            EXIT;
        ELSIF cur::time >= TIME '17:00' THEN
            cur := date_trunc('day', cur) + INTERVAL '1 day' + TIME '08:00';
            CONTINUE;
        ELSE
            EXIT;
        END IF;
    END LOOP;
    RETURN cur;
END;
$$ LANGUAGE plpgsql STABLE;
