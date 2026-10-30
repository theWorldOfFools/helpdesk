-- Migration: 023 - Undangan rapat digital + absensi QR + notulensi kolaboratif
-- Idempoten (IF NOT EXISTS), aman dijalankan ulang di database lama.
-- Hak:
--   - Buat/kelola rapat: admin + teknisi (host = pembuat).
--   - Form peserta: publik via token QR (tanpa login).
--   - Notulensi: host/admin + peserta terdaftar via token pribadi.

CREATE TABLE IF NOT EXISTS meetings (
    id SERIAL PRIMARY KEY,
    meeting_code VARCHAR(24) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    agenda TEXT NOT NULL DEFAULT '',
    host_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    start_at TIMESTAMP NOT NULL,
    end_at TIMESTAMP NOT NULL,
    location VARCHAR(255) NOT NULL DEFAULT '',
    online_url VARCHAR(500),
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled'
        CHECK (status IN ('scheduled', 'completed', 'cancelled')),
    share_token VARCHAR(64) NOT NULL,
    share_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    registration_open BOOLEAN NOT NULL DEFAULT TRUE,
    notes_locked BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT meetings_time_check CHECK (end_at > start_at)
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_meetings_share_token ON meetings(share_token);
CREATE INDEX IF NOT EXISTS idx_meetings_host ON meetings(host_id);
CREATE INDEX IF NOT EXISTS idx_meetings_start ON meetings(start_at);
CREATE INDEX IF NOT EXISTS idx_meetings_status ON meetings(status);

CREATE TABLE IF NOT EXISTS meeting_attendees (
    id SERIAL PRIMARY KEY,
    meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    access_token VARCHAR(64) NOT NULL,
    name VARCHAR(150) NOT NULL,
    installation VARCHAR(150) NOT NULL,
    department VARCHAR(150) NOT NULL,
    room VARCHAR(150) NOT NULL,
    position VARCHAR(150),
    phone VARCHAR(30),
    attendance_status VARCHAR(20) NOT NULL DEFAULT 'registered'
        CHECK (attendance_status IN ('registered', 'present', 'absent')),
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_meeting_attendee_token ON meeting_attendees(access_token);
CREATE INDEX IF NOT EXISTS idx_meeting_attendees_meeting ON meeting_attendees(meeting_id);
CREATE INDEX IF NOT EXISTS idx_meeting_attendees_registered ON meeting_attendees(registered_at);

CREATE TABLE IF NOT EXISTS meeting_notes (
    id SERIAL PRIMARY KEY,
    meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    author_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    attendee_id INTEGER REFERENCES meeting_attendees(id) ON DELETE SET NULL,
    author_name VARCHAR(150) NOT NULL,
    author_type VARCHAR(20) NOT NULL
        CHECK (author_type IN ('host', 'admin', 'participant')),
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_meeting_notes_meeting ON meeting_notes(meeting_id, created_at);
CREATE INDEX IF NOT EXISTS idx_meeting_notes_attendee ON meeting_notes(attendee_id);
