<?php
// Helper pengumuman global (dialog setelah login).
// Tabel dibuat oleh migrations/024_announcements.sql.

// Ambil pengumuman aktif yang belum ditutup user dalam 24 jam terakhir.
function getActiveAnnouncementsForUser($conn, $userId)
{
    $userId = (int)$userId;
    if ($userId <= 0) return [];

    $items = [];
    $sql = "SELECT a.id, a.judul, a.isi, a.created_at, a.ends_at
            FROM announcements a
            WHERE a.is_active = TRUE
              AND (a.ends_at IS NULL OR a.ends_at > NOW())
              AND NOT EXISTS (
                  SELECT 1
                  FROM announcement_dismissals d
                  WHERE d.announcement_id = a.id
                    AND d.user_id = $1
                    AND d.dismissed_until > NOW()
              )
            ORDER BY a.created_at DESC, a.id DESC";
    $r = @pg_query_params($conn, $sql, [$userId]);
    if ($r) {
        while ($row = pg_fetch_assoc($r)) $items[] = $row;
        pg_free_result($r);
    }
    return $items;
}

// Render isi pengumuman: dukung HTML hasil editor dan teks polos lama.
function renderAnnouncementBody($raw)
{
    $raw = (string)($raw ?? '');
    if (trim($raw) === '') return '';

    if (strip_tags($raw) !== $raw) {
        $clean = sanitizeRichText($raw);
        if ($clean !== '') return $clean;
    }
    return nl2br(e($raw));
}

// Status tampilan untuk daftar admin.
function announcementStatus($row)
{
    $isActive = ($row['is_active'] === 't' || $row['is_active'] == 1 || $row['is_active'] === true);
    if (!$isActive) {
        return ['label' => 'Nonaktif', 'badge' => 'text-bg-secondary'];
    }
    if (!empty($row['ends_at']) && strtotime($row['ends_at']) <= time()) {
        return ['label' => 'Kedaluwarsa', 'badge' => 'text-bg-dark'];
    }
    return ['label' => 'Aktif', 'badge' => 'text-bg-success'];
}
