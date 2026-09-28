-- Migration: 019 - Multi-lampiran todo SDLC (maks 10 file, total 20MB per todo)
-- Batas count/size divalidasi di aplikasi (saveTaskUploads), bukan CHECK DB.
-- Baris ikut terhapus via CASCADE; file fisik dihapus manual di delete_task.php.
-- Idempoten, aman dijalankan ulang.

CREATE TABLE IF NOT EXISTS dev_task_attachments (
    id SERIAL PRIMARY KEY,
    task_id INTEGER NOT NULL REFERENCES dev_tasks(id) ON DELETE CASCADE,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size INTEGER NOT NULL,
    uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_datt_task ON dev_task_attachments(task_id);
