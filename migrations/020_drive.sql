-- Migration: 020 - Drive internal & foldering (ala Google Drive)
-- Fitur: folder hierarkis + file multi-upload + sharing privat/divisi/publik.
-- Pola: mirror dev_task_attachments (file fisik di uploads/drive/, baris CASCADE).
-- Sharing menurun (inherited): share/visibility pada ancestor berlaku untuk subtree (dicek di aplikasi).
-- Idempoten, aman dijalankan ulang (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS drive_folders (
    id SERIAL PRIMARY KEY,
    parent_id INTEGER NULL REFERENCES drive_folders(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    division VARCHAR(100) NULL,
    visibility TEXT NOT NULL DEFAULT 'private'
        CHECK (visibility IN ('private', 'division', 'public')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CHECK (parent_id IS NULL OR parent_id <> id)
);

CREATE INDEX IF NOT EXISTS idx_drive_folders_parent ON drive_folders(parent_id);
CREATE INDEX IF NOT EXISTS idx_drive_folders_owner ON drive_folders(owner_id);
CREATE INDEX IF NOT EXISTS idx_drive_folders_visibility ON drive_folders(visibility);
CREATE INDEX IF NOT EXISTS idx_drive_folders_name ON drive_folders(name);

CREATE TABLE IF NOT EXISTS drive_files (
    id SERIAL PRIMARY KEY,
    folder_id INTEGER NOT NULL REFERENCES drive_folders(id) ON DELETE CASCADE,
    stored_path VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime VARCHAR(120) NULL,
    file_size BIGINT NOT NULL CHECK (file_size >= 0),
    uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_drive_files_folder ON drive_files(folder_id);
CREATE INDEX IF NOT EXISTS idx_drive_files_uploader ON drive_files(uploaded_by);
CREATE INDEX IF NOT EXISTS idx_drive_files_name ON drive_files(original_name);
CREATE INDEX IF NOT EXISTS idx_drive_files_created ON drive_files(created_at);

CREATE TABLE IF NOT EXISTS drive_shares (
    id SERIAL PRIMARY KEY,
    folder_id INTEGER NOT NULL REFERENCES drive_folders(id) ON DELETE CASCADE,
    shared_with_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    permission TEXT NOT NULL DEFAULT 'view'
        CHECK (permission IN ('view', 'edit')),
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (folder_id, shared_with_user_id)
);

CREATE INDEX IF NOT EXISTS idx_drive_shares_folder ON drive_shares(folder_id);
CREATE INDEX IF NOT EXISTS idx_drive_shares_user ON drive_shares(shared_with_user_id);
