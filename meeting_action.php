<?php
// Backend aksi rapat: status, link/QR, registrasi, absensi, dan notulensi host.
require_once 'config.php';
require_once 'includes/meeting_auth.php';
$user = requireRole(['admin', 'teknisi']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: meetings.php');
    exit;
}
requireCsrf();

$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
if ($id <= 0) {
    $_SESSION['flash_error'] = 'Rapat tidak ditemukan.';
    header('Location: meetings.php');
    exit;
}

$conn = getDBConnection();
$r = pg_query_params($conn, "SELECT * FROM meetings WHERE id = $1", [$id]);
$meeting = ($r && pg_num_rows($r) > 0) ? pg_fetch_assoc($r) : null;
if ($r) pg_free_result($r);
if (!$meeting) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Rapat tidak ditemukan.';
    header('Location: meetings.php');
    exit;
}
if (!meetingCanManage($meeting, $user)) {
    pg_close($conn);
    $_SESSION['flash_error'] = 'Hanya host pembuat rapat atau admin yang dapat mengelola rapat ini.';
    header('Location: view_meeting.php?id=' . $id);
    exit;
}

$redirect = 'view_meeting.php?id=' . $id;
$ok = null;
$err = null;

switch ($action) {
    case 'status_complete':
        if (pg_query_params($conn, "UPDATE meetings SET status='completed', registration_open=FALSE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Rapat ditandai selesai. Registrasi peserta ditutup.';
            logActivity($conn, $user['id'], 'meeting_complete', 'Rapat ' . $meeting['meeting_code'] . ' ditandai selesai');
        } else {
            $err = 'Gagal memperbarui status rapat.';
        }
        break;

    case 'status_cancel':
        if (pg_query_params($conn, "UPDATE meetings SET status='cancelled', registration_open=FALSE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Rapat dibatalkan. Link undangan tidak menerima registrasi baru.';
            logActivity($conn, $user['id'], 'meeting_cancel', 'Rapat ' . $meeting['meeting_code'] . ' dibatalkan');
        } else {
            $err = 'Gagal membatalkan rapat.';
        }
        break;

    case 'status_reopen':
        if (pg_query_params($conn, "UPDATE meetings SET status='scheduled', registration_open=TRUE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Rapat dibuka kembali sebagai rapat aktif.';
            logActivity($conn, $user['id'], 'meeting_reopen', 'Rapat ' . $meeting['meeting_code'] . ' dibuka kembali');
        } else {
            $err = 'Gagal membuka kembali rapat.';
        }
        break;

    case 'registration_open':
        if (pg_query_params($conn, "UPDATE meetings SET registration_open=TRUE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Registrasi peserta dibuka.';
            logActivity($conn, $user['id'], 'meeting_registration_open', 'Registrasi rapat ' . $meeting['meeting_code'] . ' dibuka');
        } else {
            $err = 'Gagal membuka registrasi.';
        }
        break;

    case 'registration_close':
        if (pg_query_params($conn, "UPDATE meetings SET registration_open=FALSE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Registrasi peserta ditutup.';
            logActivity($conn, $user['id'], 'meeting_registration_close', 'Registrasi rapat ' . $meeting['meeting_code'] . ' ditutup');
        } else {
            $err = 'Gagal menutup registrasi.';
        }
        break;

    case 'notes_lock':
        if (pg_query_params($conn, "UPDATE meetings SET notes_locked=TRUE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Notulensi dikunci. Peserta tidak dapat menambah catatan baru.';
            logActivity($conn, $user['id'], 'meeting_notes_lock', 'Notulensi rapat ' . $meeting['meeting_code'] . ' dikunci');
        } else {
            $err = 'Gagal mengunci notulensi.';
        }
        break;

    case 'notes_unlock':
        if (pg_query_params($conn, "UPDATE meetings SET notes_locked=FALSE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Notulensi dibuka kembali untuk peserta.';
            logActivity($conn, $user['id'], 'meeting_notes_unlock', 'Notulensi rapat ' . $meeting['meeting_code'] . ' dibuka');
        } else {
            $err = 'Gagal membuka notulensi.';
        }
        break;

    case 'share_enable':
        if (pg_query_params($conn, "UPDATE meetings SET share_enabled=TRUE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Link dan QR undangan diaktifkan kembali.';
            logActivity($conn, $user['id'], 'meeting_share_enable', 'Link undangan rapat ' . $meeting['meeting_code'] . ' diaktifkan');
        } else {
            $err = 'Gagal mengaktifkan link undangan.';
        }
        break;

    case 'share_disable':
        if (pg_query_params($conn, "UPDATE meetings SET share_enabled=FALSE, updated_at=NOW() WHERE id=$1", [$id])) {
            $ok = 'Link dan QR undangan dinonaktifkan.';
            logActivity($conn, $user['id'], 'meeting_share_disable', 'Link undangan rapat ' . $meeting['meeting_code'] . ' dinonaktifkan');
        } else {
            $err = 'Gagal menonaktifkan link undangan.';
        }
        break;

    case 'token_rotate':
        $newToken = bin2hex(random_bytes(16));
        if (pg_query_params($conn, "UPDATE meetings SET share_token=$1, share_enabled=TRUE, updated_at=NOW() WHERE id=$2", [$newToken, $id])) {
            $ok = 'Token undangan diacak. QR dan link lama tidak berlaku lagi.';
            logActivity($conn, $user['id'], 'meeting_token_rotate', 'Token undangan rapat ' . $meeting['meeting_code'] . ' diacak');
        } else {
            $err = 'Gagal mengacak token undangan.';
        }
        break;

    case 'attendee_status':
        $attendeeId = (int)($_POST['attendee_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        if ($attendeeId <= 0 || !in_array($status, meetingAttendeeStatuses(), true)) {
            $err = 'Data peserta/status tidak valid.';
        } else {
            $up = pg_query_params(
                $conn,
                "UPDATE meeting_attendees SET attendance_status=$1, updated_at=NOW() WHERE id=$2 AND meeting_id=$3",
                [$status, $attendeeId, $id]
            );
            if ($up && pg_affected_rows($up) > 0) {
                $ok = 'Status kehadiran peserta diperbarui.';
                logActivity($conn, $user['id'], 'meeting_attendee_status', 'Status peserta #' . $attendeeId . ' rapat ' . $meeting['meeting_code'] . ' => ' . $status);
            } else {
                $err = 'Peserta tidak ditemukan pada rapat ini.';
            }
        }
        break;

    case 'attendee_delete':
        $attendeeId = (int)($_POST['attendee_id'] ?? 0);
        if ($attendeeId <= 0) {
            $err = 'Peserta tidak valid.';
        } else {
            $del = pg_query_params($conn, "DELETE FROM meeting_attendees WHERE id=$1 AND meeting_id=$2", [$attendeeId, $id]);
            if ($del && pg_affected_rows($del) > 0) {
                $ok = 'Peserta dihapus dari daftar absensi.';
                logActivity($conn, $user['id'], 'meeting_attendee_delete', 'Peserta #' . $attendeeId . ' rapat ' . $meeting['meeting_code'] . ' dihapus');
            } else {
                $err = 'Peserta tidak ditemukan pada rapat ini.';
            }
        }
        break;

    case 'note_add':
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '') {
            $err = 'Catatan tidak boleh kosong.';
        } elseif (mb_strlen($body, 'UTF-8') > 30000) {
            $err = 'Catatan terlalu panjang (maksimal 30.000 karakter).';
        } else {
            $clean = sanitizeRichText(prepareRichTextForStorage($body));
            if (trim(strip_tags($clean)) === '' && strpos($clean, '<img') === false) {
                $err = 'Catatan tidak boleh kosong.';
            } else {
                $authorType = ((int)$meeting['host_id'] === (int)$user['id']) ? 'host' : 'admin';
                $ins = pg_query_params(
                    $conn,
                    "INSERT INTO meeting_notes (meeting_id, author_user_id, author_name, author_type, body) VALUES ($1,$2,$3,$4,$5)",
                    [$id, (int)$user['id'], (string)$user['name'], $authorType, $clean]
                );
                if ($ins) {
                    $ok = 'Catatan notulensi disimpan.';
                    logActivity($conn, $user['id'], 'meeting_note_add', 'Catatan notulensi ditambahkan ke rapat ' . $meeting['meeting_code']);
                } else {
                    $err = 'Gagal menyimpan catatan: ' . pg_last_error($conn);
                }
            }
        }
        break;

    case 'note_delete':
        $noteId = (int)($_POST['note_id'] ?? 0);
        if ($noteId <= 0) {
            $err = 'Catatan tidak valid.';
        } else {
            $del = pg_query_params($conn, "DELETE FROM meeting_notes WHERE id=$1 AND meeting_id=$2", [$noteId, $id]);
            if ($del && pg_affected_rows($del) > 0) {
                $ok = 'Catatan notulensi dihapus.';
                logActivity($conn, $user['id'], 'meeting_note_delete', 'Catatan #' . $noteId . ' rapat ' . $meeting['meeting_code'] . ' dihapus');
            } else {
                $err = 'Catatan tidak ditemukan pada rapat ini.';
            }
        }
        break;

    case 'delete':
        if (($user['role'] ?? '') !== 'admin') {
            $err = 'Hanya admin yang dapat menghapus rapat secara permanen.';
        } else {
            $del = pg_query_params($conn, "DELETE FROM meetings WHERE id=$1", [$id]);
            if ($del && pg_affected_rows($del) > 0) {
                logActivity($conn, $user['id'], 'meeting_delete', 'Rapat ' . $meeting['meeting_code'] . ' dihapus permanen');
                pg_close($conn);
                $_SESSION['flash_ok'] = 'Rapat ' . $meeting['meeting_code'] . ' dihapus permanen.';
                header('Location: meetings.php');
                exit;
            }
            $err = 'Gagal menghapus rapat.';
        }
        break;

    default:
        $err = 'Aksi tidak dikenal.';
        break;
}

pg_close($conn);
if ($ok !== null) $_SESSION['flash_ok'] = $ok;
if ($err !== null) $_SESSION['flash_error'] = $err;
header('Location: ' . $redirect);
exit;
