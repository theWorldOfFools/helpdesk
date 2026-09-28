-- Migration: 018 - Todo SDLC multi-assignee (many-to-many, mirror change_request_pics)
-- Kelola (tambah/hapus): admin + pembuat todo (canManageAssignees).
-- owner_id DIPERTAHANKAN sebagai penanggung jawab utama (= pembuat saat create;
-- pindah saat take/reassign admin). Workload dihitung dari keanggotaan tabel ini.
-- Idempoten, aman dijalankan ulang.

CREATE TABLE IF NOT EXISTS dev_task_assignees (
    task_id INTEGER NOT NULL REFERENCES dev_tasks(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    assigned_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_dtaskas_task ON dev_task_assignees(task_id);
CREATE INDEX IF NOT EXISTS idx_dtaskas_user ON dev_task_assignees(user_id);

-- Backfill: owner/creator lama langsung jadi assignee.
INSERT INTO dev_task_assignees (task_id, user_id)
SELECT id, COALESCE(owner_id, created_by) FROM dev_tasks
WHERE COALESCE(owner_id, created_by) IS NOT NULL
ON CONFLICT DO NOTHING;
