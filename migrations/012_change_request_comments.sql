-- Migration: 012 - Komentar / diskusi Change Request
-- Mirror 005 ticket_comments untuk CR. Idempoten (IF NOT EXISTS).
-- Dipakai view_cr.php (Diskusi) + cr_action.php (catatan tiap tindak lanjut).

CREATE TABLE IF NOT EXISTS change_request_comments (
    id SERIAL PRIMARY KEY,
    cr_id INTEGER NOT NULL REFERENCES change_requests(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_crc_cr ON change_request_comments(cr_id);
CREATE INDEX IF NOT EXISTS idx_crc_created ON change_request_comments(created_at);
