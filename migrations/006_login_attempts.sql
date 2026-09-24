-- Migration: 006 - Rate limit login (5 gagal / 5 menit per IP+username)
CREATE TABLE IF NOT EXISTS login_attempts (
    id SERIAL PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE
);
CREATE INDEX IF NOT EXISTS idx_login_ip_user_time ON login_attempts(ip, username, attempted_at);
