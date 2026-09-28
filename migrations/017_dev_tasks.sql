-- Migration: 017 - Mini-todo SDLC internal (teknisi + admin)
-- Mirip tiket tapi untuk proses SDLC sendiri: tanpa SLA/divisi/pelapor.
-- Fase: backlog -> siap -> development -> testing -> deploy -> done.
-- Relasi opsional ke SATU sumber: ticket_id ATAU cr_id (validasi di aplikasi).
-- Idempoten, aman dijalankan ulang (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS dev_tasks (
    id SERIAL PRIMARY KEY,
    task_code VARCHAR(20) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    phase VARCHAR(20) NOT NULL DEFAULT 'backlog'
        CHECK (phase IN ('backlog','siap','development','testing','deploy','done')),
    priority VARCHAR(20) NOT NULL DEFAULT 'medium',
    owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    ticket_id INTEGER REFERENCES tickets(id) ON DELETE SET NULL,
    cr_id INTEGER REFERENCES change_requests(id) ON DELETE SET NULL,
    due_date DATE NULL,
    estimate_hours INTEGER NULL CHECK (estimate_hours IS NULL OR (estimate_hours >= 1 AND estimate_hours <= 1000)),
    done_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_dtask_phase ON dev_tasks(phase);
CREATE INDEX IF NOT EXISTS idx_dtask_priority ON dev_tasks(priority);
CREATE INDEX IF NOT EXISTS idx_dtask_owner ON dev_tasks(owner_id);
CREATE INDEX IF NOT EXISTS idx_dtask_ticket ON dev_tasks(ticket_id);
CREATE INDEX IF NOT EXISTS idx_dtask_cr ON dev_tasks(cr_id);
CREATE INDEX IF NOT EXISTS idx_dtask_created ON dev_tasks(created_at);
CREATE INDEX IF NOT EXISTS idx_dtask_due ON dev_tasks(due_date);

-- Diskusi per todo (mirror ticket_comments / change_request_comments)
CREATE TABLE IF NOT EXISTS dev_task_comments (
    id SERIAL PRIMARY KEY,
    task_id INTEGER NOT NULL REFERENCES dev_tasks(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_dtaskc_task ON dev_task_comments(task_id);

-- Riwayat fase / audit (mirror ticket_history)
CREATE TABLE IF NOT EXISTS dev_task_history (
    id SERIAL PRIMARY KEY,
    task_id INTEGER NOT NULL REFERENCES dev_tasks(id) ON DELETE CASCADE,
    actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    from_phase VARCHAR(20) NULL,
    to_phase VARCHAR(20) NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_dtaskh_task ON dev_task_history(task_id);
CREATE INDEX IF NOT EXISTS idx_dtaskh_created ON dev_task_history(created_at);
