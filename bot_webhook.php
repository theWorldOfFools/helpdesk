<?php
// Webhook Telegram: 1 bot -> group chat, submenu global dinamis, kenal Teknisi X.
// Setup sekali: set URL https://domain/bot_webhook.php?secret=XXX via telegram_admin.php.
// BotFather: /setprivacy -> Disable + jadikan bot admin group agar baca command group.
// Di group bot hanya membalas command (/cmd) atau saat di-tag @username bot; obrolan biasa diabaikan.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/telegram.php';
require_once __DIR__ . '/includes/cr_auth.php';

header('Content-Type: application/json; charset=utf-8');

// 1) Verifikasi secret webhook (?secret=) bila dikonfigurasi.
$cfg = tgConfig();
$need = (string)($cfg['secret'] ?? '');
$gotSecret = (string)($_GET['secret'] ?? '');
if ($need !== '' && $gotSecret !== $need) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$upd = json_decode((string)$raw, true);
if (!is_array($upd)) {
    echo json_encode(['ok' => true]);
    exit;
}

// 2) Normalisasi message vs callback_query.
$chatId = null;
$chatTitle = null;
$chatType = null;
$tgId = null;
$tgUsername = null;
$tgName = null;
$msgId = null;
$text = '';
$cbId = null;
$cbData = null;

if (!empty($upd['callback_query'])) {
    $cb = $upd['callback_query'];
    $cbId = $cb['id'] ?? null;
    $cbData = trim((string)($cb['data'] ?? ''));
    $tgId = (int)($cb['from']['id'] ?? 0);
    $tgUsername = $cb['from']['username'] ?? null;
    $tgName = trim(($cb['from']['first_name'] ?? '') . ' ' . ($cb['from']['last_name'] ?? ''));
    $chatId = $cb['message']['chat']['id'] ?? null;
    $chatTitle = $cb['message']['chat']['title'] ?? null;
    $chatType = $cb['message']['chat']['type'] ?? null;
    $msgId = $cb['message']['message_id'] ?? null;
    $text = $cbData;
} elseif (!empty($upd['message']['text'])) {
    $m = $upd['message'];
    $chatId = $m['chat']['id'] ?? null;
    $chatTitle = $m['chat']['title'] ?? null;
    $chatType = $m['chat']['type'] ?? null;
    $tgId = (int)($m['from']['id'] ?? 0);
    $tgUsername = $m['from']['username'] ?? null;
    $tgName = trim(($m['from']['first_name'] ?? '') . ' ' . ($m['from']['last_name'] ?? ''));
    $msgId = $m['message_id'] ?? null;
    $text = trim((string)$m['text']);
} else {
    echo json_encode(['ok' => true]);
    exit;
}

if (empty($chatId) || empty($tgId)) {
    echo json_encode(['ok' => true]);
    exit;
}

// 2b) Di group: hanya merespons command atau saat bot di-tag (@username).
// Pesan obrolan lain diabaikan diam-diam agar bot tidak berisik.
if (empty($upd['callback_query']) && in_array((string)$chatType, ['group', 'supergroup'], true)) {
    $bot = tgBotIdentity();
    $botUser = strtolower((string)($bot['username'] ?? ''));
    $mentioned = false;

    foreach (($upd['message']['entities'] ?? []) as $en) {
        $type = $en['type'] ?? '';
        if ($type === 'mention' && $botUser !== '') {
            $mt = strtolower(mb_substr($text, (int)($en['offset'] ?? 0), (int)($en['length'] ?? 0)));
            if ($mt === '@' . $botUser) $mentioned = true;
        } elseif ($type === 'text_mention' && !empty($bot['id']) && (int)($en['user']['id'] ?? 0) === (int)$bot['id']) {
            $mentioned = true;
        }
        if ($mentioned) break;
    }
    if (!$mentioned && $botUser !== '' && preg_match('/(?<![\w])@' . preg_quote($botUser, '/') . '\b/i', $text)) {
        $mentioned = true;
    }

    if ($mentioned) {
        // Buang tag dari teks; bila tak ada command setelahnya, tampilkan menu utama.
        $stripped = $botUser !== ''
            ? trim((string)preg_replace('/(?<![\w])@' . preg_quote($botUser, '/') . '\b/i', '', $text))
            : '';
        $text = ($stripped !== '' && $stripped[0] === '/') ? $stripped : '/help';
    }

    $first = strtok($text, " \t\n");
    $isCommand = is_string($first) && $first !== '' && $first[0] === '/';
    $cmdTarget = null;
    if ($isCommand && strpos($first, '@') !== false) {
        $cmdTarget = strtolower(substr($first, strpos($first, '@') + 1));
    }
    $forOtherBot = $isCommand && $cmdTarget !== null && $cmdTarget !== $botUser;
    $fromBot = !empty($upd['message']['from']['is_bot']);

    if ($fromBot || $forOtherBot || (!$isCommand && !$mentioned)) {
        echo json_encode(['ok' => true]);
        exit;
    }
}

$conn = getDBConnection();

// 3) Daftarkan chat (auto-allow group/DM, admin bisa revoke di telegram_admin.php).
try {
    @pg_query_params($conn,
        "INSERT INTO telegram_chats (chat_id, title, chat_type, is_allowed) VALUES ($1,$2,$3,TRUE) ON CONFLICT (chat_id) DO UPDATE SET title = EXCLUDED.title",
        [(int)$chatId, mb_substr((string)($chatTitle ?: $chatType), 0, 255), mb_substr((string)($chatType ?: 'group'), 0, 20)]);
} catch (Throwable $e) {
}
// Tolak chat yang di-revoke.
$isAllowed = true;
try {
    $r = @pg_query_params($conn, "SELECT is_allowed FROM telegram_chats WHERE chat_id = $1", [(int)$chatId]);
    if ($r && pg_num_rows($r) > 0) $isAllowed = pg_fetch_result($r, 0, 0) === 't' || pg_fetch_result($r, 0, 0) == 1;
    if ($r) pg_free_result($r);
} catch (Throwable $e) {
}
if (!$isAllowed) {
    tgSend($chatId, '⛔ Group/chat ini belum diizinkan. Minta admin aktifkan di menu Telegram Admin.');
    tgLog($conn, $chatId, $tgId, $tgUsername, 'blocked', $text, false);
    pg_close($conn);
    echo json_encode(['ok' => true]);
    exit;
}

// 4) Kenali Teknisi X via tabel link.
$linked = tgResolveUser($conn, $tgId);
$role = $linked['role'] ?? 'guest';
$myName = $linked['name'] ?? $tgName;

function whSend($chatId, $html, $menus = null)
{
    $kb = $menus !== null ? tgKeyboardFromMenus($menus) : null;
    $r = tgSend($chatId, $html, $kb);
    return (bool)$r;
}

function whWelcome($conn, $role, $myName)
{
    $menus = tgMenusFor($conn, $role);
    $sapaan = $role === 'guest'
        ? "Halo <b>" . e($myName) . "</b>! Aku bot Helpdesk IT.\nTautkan akun dulu via web <b>Profil → Hubungkan Telegram</b> lalu kirim <code>/hubung KODE</code> agar aku kenal kamu sebagai Teknisi X."
        : "Halo " . tgMention($GLOBALS['tgId'] ?? 0, $myName) . " (<b>" . e($role) . "</b>)! Pilih menu:";
    return [$sapaan, $menus];
}

$appUrl = rtrim((string)env('APP_URL', ''), '/');
$linkFor = function ($path) use ($appUrl) {
    return $appUrl !== '' ? $appUrl . '/' . ltrim($path, '/') : $path;
};

// Pisahkan command + argumen, buang @namabot bila ada (format group).
$cmd = strtolower(strtok($text, " \t\n"));
$cmd = preg_replace('/@.+$/', '', $cmd);
$arg = trim(mb_substr($text, mb_strlen(strtok($text, " \t\n"))));
$ok = true;
$reply = '';
$replyKb = null;

try {
    // ---- /start [/hubung] : dukung deep-link t.me?start=KODE ----
    if ($cmd === '/start' || $cmd === 'start') {
        if ($arg !== '' && preg_match('/^\d{6}$/', $arg)) {
            $cmd = '/hubung'; // jatuh ke handler hubung di bawah
        } else {
            [$sapaan, $menus] = whWelcome($conn, $role, $myName);
            $reply = $sapaan;
            $replyKb = tgKeyboardFromMenus($menus);
        }
    }

    if ($cmd === '/help' || $cmd === 'help' || ($cmd === '' && $cbData === '')) {
        $reply = "<b>Perintah bot</b>\n"
            . "<code>/start</code> - menu utama\n<code>/siapa</code> - cek identitas\n"
            . "<code>/hubung KODE</code> - tautkan akun (kode dari Profil web, via DM lebih aman)\n"
            . "<code>/putus</code> - putus tautan\n<code>/tiket_open</code> - 5 tiket terbuka\n"
            . "<code>/cr_aktif</code> - 5 CR aktif\n<code>/tiket_saya</code> - tiketmu (wajib taut)\n"
            . "<code>/antrean</code> - antrean divisimu (teknisi/admin)\n<code>/oncall</code> - siapa jaga hari ini\n"
            . "<code>/laporan</code> - ringkasan harian (teknisi/admin)\n<code>/kb kata</code> - cari solusi";
        $replyKb = tgKeyboardFromMenus(tgMenusFor($conn, $role));
    } elseif ($cmd === '/siapa' || $cmd === 'siapa') {
        $reply = $linked
            ? "✅ Kamu terhubung sebagai <b>" . e($linked['name']) . "</b> (@" . e($linked['username']) . " · " . e($linked['role']) . " · " . e($linked['division'] ?? '-') . ", via " . e($linked['linked_via'] ?? 'otp') . ")."
            : "❌ Belum terhubung. Buka web <b>Profil → Hubungkan Telegram</b>, generate kode, lalu kirim <code>/hubung KODE</code> di sini (lebih aman via DM).";
    } elseif ($cmd === '/putus' || $cmd === 'putus') {
        @pg_query_params($conn, "DELETE FROM telegram_identities WHERE telegram_id = $1", [$tgId]);
        if ($linked) logActivity($conn, $linked['id'], 'telegram_unlink', 'Putus via bot tg_id=' . $tgId);
        $role = 'guest';
        $reply = "🔌 Tautan diputus. Kamu kini tamu.";
        $replyKb = tgKeyboardFromMenus(tgMenusFor($conn, 'guest'));
    } elseif ($cmd === '/hubung' || $cmd === 'hubung') {
        if (!preg_match('/^\d{6}$/', $arg)) {
            $reply = "Format: <code>/hubung 123456</code> (6 digit dari halaman Profil web).";
        } else {
            $hash = hash('sha256', $arg);
            $row = null;
            try {
                $r = @pg_query_params($conn,
                    "SELECT id, user_id, expires_at, used_at, attempts FROM telegram_link_codes WHERE code_hash = $1 ORDER BY id DESC LIMIT 1", [$hash]);
                if ($r && pg_num_rows($r) > 0) $row = pg_fetch_assoc($r);
                if ($r) pg_free_result($r);
            } catch (Throwable $e) {
            }
            if (!$row) {
                $reply = "❌ Kode salah. Cek ulang di Profil web lalu generate baru bila perlu.";
                $ok = false;
            } elseif (!empty($row['used_at'])) {
                $reply = "❌ Kode sudah dipakai. Generate kode baru di Profil.";
                $ok = false;
            } elseif (strtotime($row['expires_at']) < time()) {
                $reply = "⌛ Kode kedaluwarsa (10 menit). Generate ulang di Profil.";
                $ok = false;
            } elseif ((int)$row['attempts'] >= 5) {
                $reply = "⛔ Kode diblokir (5x salah). Generate kode baru.";
                $ok = false;
            } else {
                $codeId = (int)$row['id'];
                $uid = (int)$row['user_id'];
                // 1 telegram = 1 user: tolak bila tg_id sudah milik user lain, kecuali milik sendiri (re-link).
                $takenBy = null;
                try {
                    $t = @pg_query_params($conn, "SELECT user_id FROM telegram_identities WHERE telegram_id = $1", [$tgId]);
                    if ($t && pg_num_rows($t) > 0) $takenBy = (int)pg_fetch_result($t, 0, 0);
                    if ($t) pg_free_result($t);
                } catch (Throwable $e) {
                }
                if ($takenBy && $takenBy !== $uid) {
                    @pg_query_params($conn, "UPDATE telegram_link_codes SET attempts = attempts + 1 WHERE id = $1", [$codeId]);
                    $reply = "❌ Telegram ini sudah tertaut ke akun lain. Kirim <code>/putus</code> dulu bila ingin pindah.";
                    $ok = false;
                } else {
                    @pg_query($conn, 'BEGIN');
                    @pg_query_params($conn, "DELETE FROM telegram_identities WHERE user_id = $1 OR telegram_id = $2", [$uid, $tgId]);
                    $ins = @pg_query_params($conn,
                        "INSERT INTO telegram_identities (user_id, telegram_id, telegram_username, linked_via) VALUES ($1,$2,$3,'otp')",
                        [$uid, $tgId, $tgUsername]);
                    @pg_query_params($conn, "UPDATE telegram_link_codes SET used_at = NOW() WHERE id = $1", [$codeId]);
                    if ($ins) {
                        @pg_query($conn, 'COMMIT');
                        $u = @pg_query_params($conn, "SELECT name, role, division FROM users WHERE id = $1", [$uid]);
                        $nm = $u && pg_num_rows($u) > 0 ? pg_fetch_assoc($u) : ['name' => 'Pengguna', 'role' => '-', 'division' => '-'];
                        if ($u) pg_free_result($u);
                        logActivity($conn, $uid, 'telegram_link', 'Taut via bot tg_id=' . $tgId);
                        $linked = tgResolveUser($conn, $tgId);
                        $role = $linked['role'] ?? 'guest';
                        $reply = "✅ Terhubung sebagai <b>" . e($nm['name']) . "</b> (" . e($nm['role']) . " · " . e($nm['division'] ?? '-') . ").";
                        $replyKb = tgKeyboardFromMenus(tgMenusFor($conn, $role));
                        // Hapus pesan kode di group agar tidak bocor.
                        if (($chatType === 'group' || $chatType === 'supergroup') && $msgId) tgDeleteMessage($chatId, $msgId);
                    } else {
                        @pg_query($conn, 'ROLLBACK');
                        $reply = "❌ Gagal menaut. Coba lagi.";
                        $ok = false;
                    }
                }
            }
        }
    } elseif (in_array($cmd, ['/tiket_open', '/cr_aktif', '/tiket_saya', '/antrean', '/oncall', '/laporan', '/kb'], true)
        || str_starts_with($cmd, 'menu:') || str_starts_with((string)$cbData, 'menu:')) {
        // Callback tombol -> petakan ke action menu + cek min_role.
        $action = '';
        $payloadArg = $arg;
        $cbRef = str_starts_with((string)$cbData, 'menu:') ? (string)$cbData : (str_starts_with($cmd, 'menu:') ? $cmd : '');
        if ($cbRef !== '') {
            $mid = (int)substr($cbRef, 5);
            $m = null;
            try {
                $r = @pg_query_params($conn, "SELECT action, payload, min_role, label FROM telegram_menus WHERE id = $1 AND is_active = TRUE", [$mid]);
                if ($r && pg_num_rows($r) > 0) $m = pg_fetch_assoc($r);
                if ($r) pg_free_result($r);
            } catch (Throwable $e) {
            }
            if (!$m) {
                if ($cbId) tgAnswerCallback($cbId, 'Menu tidak aktif');
                throw new Exception('__answered__');
            }
            if (tgRoleLevel($m['min_role'] ?? 'guest') > tgRoleLevel($role)) {
                $reply = "⛔ Menu <b>" . e($m['label']) . "</b> butuh role minimal <b>" . e($m['min_role']) . "</b>. " . ($linked ? '' : 'Tautkan dulu via <code>/hubung KODE</code>.');
                $ok = false;
            } else {
                $action = $m['action'];
                if (!empty($m['payload'])) $payloadArg = $m['payload'] . ' ' . $payloadArg;
            }
        } else {
            $action = ['/tiket_open' => 'tiket_open', '/cr_aktif' => 'cr_aktif', '/tiket_saya' => 'tiket_saya', '/antrean' => 'antrean', '/oncall' => 'oncall_today', '/laporan' => 'report_daily', '/kb' => 'kb_search'][$cmd] ?? '';
        }

        if ($action === 'tiket_open' && $reply === '') {
            $rows = [];
            try {
                $r = @pg_query($conn, "SELECT id, ticket_number, title, status, priority, assigned_to FROM tickets WHERE status IN ('open','in_progress') ORDER BY CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, created_at DESC LIMIT 5");
                if ($r) {
                    while ($x = pg_fetch_assoc($r)) $rows[] = $x;
                    pg_free_result($r);
                }
            } catch (Throwable $e) {
            }
            if (!$rows) $reply = "🎉 Tidak ada tiket open/in_progress.";
            else {
                $reply = "<b>🎫 Tiket Open (5 terbaru)</b>\n";
                foreach ($rows as $t) {
                    if ($linked && !canViewTicket($t, $linked)) continue;
                    $reply .= "\n<b>" . e($t['ticket_number']) . "</b> [" . e($t['status']) . "/" . e($t['priority']) . "]\n" . e(mb_substr($t['title'], 0, 80)) . "\n" . tgAssigneeLine($conn, $t['assigned_to']) . " · <a href=\"" . e($linkFor('view_ticket.php?id=' . $t['id'])) . "\">buka</a>\n";
                }
            }
        } elseif ($action === 'cr_aktif' && $reply === '') {
            require_once __DIR__ . '/includes/cr_auth.php';
            $rows = [];
            try {
                $r = @pg_query($conn, "SELECT id, cr_number, title, status, assigned_to FROM change_requests WHERE status IN ('open','in_progress') ORDER BY created_at DESC LIMIT 5");
                if ($r) {
                    while ($x = pg_fetch_assoc($r)) $rows[] = $x;
                    pg_free_result($r);
                }
            } catch (Throwable $e) {
            }
            if (!$rows) $reply = "🎉 Tidak ada CR aktif.";
            else {
                $reply = "<b>📋 CR Aktif (5 terbaru)</b>\n";
                foreach ($rows as $t) {
                    if ($linked && !canViewCr($t, $linked)) continue;
                    $reply .= "\n<b>" . e($t['cr_number']) . "</b> [" . e($t['status']) . "]\n" . e(mb_substr($t['title'], 0, 80)) . "\n" . tgAssigneeLine($conn, $t['assigned_to']) . " · <a href=\"" . e($linkFor('view_cr.php?id=' . $t['id'])) . "\">buka</a>\n";
                }
            }
        } elseif ($action === 'tiket_saya' && $reply === '') {
            if (!$linked) {
                $reply = "🔗 Tautkan dulu: kirim <code>/hubung KODE</code> (kode dari Profil web).";
                $ok = false;
            } else {
                $rows = [];
                try {
                    $r = @pg_query_params($conn, "SELECT id, ticket_number, title, status FROM tickets WHERE user_id = $1 ORDER BY created_at DESC LIMIT 5", [$linked['id']]);
                    if ($r) {
                        while ($x = pg_fetch_assoc($r)) $rows[] = $x;
                        pg_free_result($r);
                    }
                } catch (Throwable $e) {
                }
                $reply = $rows ? "<b>🗂 Tiketmu</b>\n" : "Belum ada tiket atas namamu.";
                foreach ($rows as $t) $reply .= "\n<b>" . e($t['ticket_number']) . "</b> [" . e($t['status']) . "] " . e(mb_substr($t['title'], 0, 70)) . " · <a href=\"" . e($linkFor('view_ticket.php?id=' . $t['id'])) . "\">buka</a>";
            }
        } elseif ($action === 'antrean' && $reply === '') {
            if (!$linked || !in_array($linked['role'], ['teknisi', 'admin'], true)) {
                $reply = "⛔ Khusus teknisi/admin yang tertaut.";
                $ok = false;
            } else {
                $rows = [];
                try {
                    if ($linked['role'] === 'admin') {
                        $r = @pg_query($conn, "SELECT ticket_number, title, division FROM tickets WHERE status = 'open' ORDER BY created_at DESC LIMIT 8");
                    } else {
                        $r = @pg_query_params($conn, "SELECT ticket_number, title, division FROM tickets WHERE status = 'open' AND division = $1 ORDER BY created_at DESC LIMIT 8", [$linked['division']]);
                    }
                    if ($r) {
                        while ($x = pg_fetch_assoc($r)) $rows[] = $x;
                        pg_free_result($r);
                    }
                } catch (Throwable $e) {
                }
                $reply = $rows ? "<b>🧑‍💻 Antrean" . ($linked['role'] === 'admin' ? '' : ' ' . e($linked['division'] ?? '')) . "</b>\n" : "Antrean kosong. 🎉";
                foreach ($rows as $t) $reply .= "\n<b>" . e($t['ticket_number']) . "</b> " . e(mb_substr($t['title'], 0, 70)) . " (" . e($t['division']) . ")";
            }
        } elseif ($action === 'oncall_today' && $reply === '') {
            $rows = [];
            try {
                $r = @pg_query($conn, "SELECT u.name, u.id AS uid, s.peran FROM oncall_schedules s JOIN users u ON u.id = s.user_id WHERE s.tanggal = CURRENT_DATE ORDER BY s.peran ASC");
                if ($r) {
                    while ($x = pg_fetch_assoc($r)) $rows[] = $x;
                    pg_free_result($r);
                }
            } catch (Throwable $e) {
            }
            if (!$rows) $reply = "📞 Hari ini tidak ada jadwal oncall.";
            else {
                $reply = "<b>📞 Oncall hari ini (" . date('d M Y') . ")</b>\n";
                foreach ($rows as $o) $reply .= "\n• " . tgMention(tgFindTelegramIdForUser($conn, $o['uid']), $o['name']) . " (" . e($o['peran']) . ")";
            }
        } elseif ($action === 'report_daily' && $reply === '') {
            if (!$linked || !in_array($linked['role'], ['teknisi', 'admin'], true)) {
                $reply = "⛔ Ringkasan khusus teknisi/admin yang tertaut.";
                $ok = false;
            } else {
                $c = ['baru' => 0, 'open' => 0, 'prog' => 0, 'done' => 0];
                try {
                    $r = @pg_query($conn, "SELECT COUNT(*) FILTER (WHERE created_at::date = CURRENT_DATE) AS baru, COUNT(*) FILTER (WHERE status='open') AS open, COUNT(*) FILTER (WHERE status='in_progress') AS prog, COUNT(*) FILTER (WHERE status IN ('resolved','closed') AND COALESCE(resolved_at, closed_at, updated_at)::date = CURRENT_DATE) AS done FROM tickets");
                    if ($r && pg_num_rows($r) > 0) $c = pg_fetch_assoc($r);
                    if ($r) pg_free_result($r);
                } catch (Throwable $e) {
                }
                $reply = "<b>📊 Laporan " . date('d M Y') . "</b>\nBaru hari ini: <b>{$c['baru']}</b>\nOpen: <b>{$c['open']}</b> · In Progress: <b>{$c['prog']}</b> · Selesai hari ini: <b>{$c['done']}</b>\n<a href=\"" . e($linkFor('laporan.php')) . "\">buka laporan</a>";
            }
        } elseif (in_array($action, ['kb_search', 'kb_hint'], true) && $reply === '') {
            $kw = trim((string)$payloadArg);
            if ($kw === '') {
                $reply = "Gunakan: <code>/kb kata_kunci</code> mis. <code>/kb printer</code>.";
            } else {
                $rows = [];
                try {
                    $r = @pg_query_params($conn, "SELECT id, title FROM kb_articles WHERE is_published = TRUE AND (title ILIKE $1 OR content ILIKE $1) ORDER BY updated_at DESC LIMIT 5", ['%' . $kw . '%']);
                    if ($r) {
                        while ($x = pg_fetch_assoc($r)) $rows[] = $x;
                        pg_free_result($r);
                    }
                } catch (Throwable $e) {
                }
                $reply = $rows ? "<b>🔍 KB: " . e($kw) . "</b>\n" : "Tidak ketemu untuk \"" . e($kw) . "\".";
                foreach ($rows as $a) $reply .= "\n• " . e($a['title']) . " · <a href=\"" . e($linkFor('kb.php')) . "\">buka KB</a>";
            }
        } elseif ($action === 'help' && $reply === '') {
            $reply = "Pilih tombol di bawah atau ketik <code>/help</code>.";
            $replyKb = tgKeyboardFromMenus(tgMenusFor($conn, $role));
        } elseif ($reply === '') {
            $reply = "Perintah tidak dikenal. Ketik <code>/help</code>.";
            $ok = false;
        }
        if ($cbId && $reply !== '' && empty($replyKb)) tgAnswerCallback($cbId, 'OK');
    } else {
        $reply = "Perintah tidak dikenal. Ketik <code>/help</code> atau tekan tombol menu.";
        $replyKb = tgKeyboardFromMenus(tgMenusFor($conn, $role));
        $ok = false;
    }
} catch (Throwable $e) {
    if (($e->getMessage() ?? '') === '__answered__') {
        tgLog($conn, $chatId, $tgId, $tgUsername, $text, null, false);
        pg_close($conn);
        echo json_encode(['ok' => true]);
        exit;
    }
    $reply = "⚠️ Gagal proses. Coba lagi.";
    $ok = false;
}

if ($reply !== '') {
    // Sambut + sertakan keyboard menu bila belum ada keyboard khusus.
    if ($replyKb === null && in_array($cmd, ['/start', '/help', 'help'], true)) $replyKb = tgKeyboardFromMenus(tgMenusFor($conn, $role));
    $sent = (bool)tgSend($chatId, $reply, $replyKb);
    if (!$sent) $ok = false;
}
tgLog($conn, $chatId, $tgId, $tgUsername, mb_substr($text, 0, 100), null, $ok);
pg_close($conn);
echo json_encode(['ok' => true]);
