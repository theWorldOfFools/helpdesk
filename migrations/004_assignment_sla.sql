-- Migration: 004 - Assignment + SLA timestamps
-- Mendukung: tombol Tindak Lanjut teknisi, workload per-teknisi, SLA breach, leaderboard tercepat

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS sla_due_at TIMESTAMP;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMP;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS closed_at TIMESTAMP;

CREATE INDEX IF NOT EXISTS idx_tickets_assigned ON tickets(assigned_to);
CREATE INDEX IF NOT EXISTS idx_tickets_sla_due ON tickets(sla_due_at);
CREATE INDEX IF NOT EXISTS idx_tickets_resolved_at ON tickets(resolved_at);

-- Backfill ringan: isi resolved_at/closed_at dari updated_at untuk tiket yang sudah selesai
UPDATE tickets SET resolved_at = COALESCE(resolved_at, updated_at)
WHERE status IN ('resolved','closed') AND resolved_at IS NULL;
UPDATE tickets SET closed_at = COALESCE(closed_at, updated_at)
WHERE status = 'closed' AND closed_at IS NULL;
