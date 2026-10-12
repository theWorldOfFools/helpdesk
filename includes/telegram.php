<?php
// Helper Telegram: kirim pesan, keyboard dinamis, resolve Teknisi X, broadcast.
// Ikut konvensi repo: fungsi plain, gagal diam-diam agar tidak blokir alur tiket/CR.

if (!function_exists('tgConfig')) {
    function tgConfig()
    {
        return [
            'token' => env('TELEGRAM_BOT_TOKEN', ''),
            'secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
            'enabled' => env('TELEGRAM_ENABLED', appSetting('telegram_enabled', '0')) === '1'
                || appSetting('telegram_enabled', '0') === '1',
            'default_chat' => env('TELEGRAM_CHAT_ID', appSetting('telegram_default_chat_id', '')),
            'username' => env('TELEGRAM_BOT_USERNAME', appSetting('telegram_bot_username', '')),
        ];
    }
}

if (!function_exists('tgEnabled')) {
    function tgEnabled()
    {
        $c = tgConfig();
        return $c['enabled'] && $c['token'] !== '';
    }
}

if (!function_exists('tgApi')) {
    // Panggil Bot API via POST JSON. Timeout pendek agar hook tidak lemot.
    function tgApi($method, array $params = [])
    {
        $c = tgConfig();
        if ($c['token'] === '') return false;
        $url = 'https://api.telegram.org/bot' . $c['token'] . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $out = curl_exec($ch);
        curl_close($ch);
        if ($out === false) return false;
        $d = json_decode($out, true);
        return (is_array($d) && !empty($d['ok'])) ? $d : false;
    }
}

if (!function_exists('tgSend')) {
    function tgSend($chatId, $text, $keyboard = null, $parseMode = 'HTML')
    {
        $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parseMode, 'disable_web_page_preview' => true];
        if (is_array($keyboard)) $p['reply_markup'] = ['inline_keyboard' => $keyboard];
        return tgApi('sendMessage', $p);
    }
}

if (!function_exists('tgDeleteMessage')) {
    function tgDeleteMessage($chatId, $msgId)
    {
        return tgApi('deleteMessage', ['chat_id' => $chatId, 'message_id' => (int)$msgId]);
    }
}

if (!function_exists('tgAnswerCallback')) {
    function tgAnswerCallback($cbId, $text = '')
    {
        return tgApi('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => mb_substr($text, 0, 180)]);
    }
}

if (!function_exists('tgRoleLevel')) {
    function tgRoleLevel($role)
    {
        return ['guest' => 0, 'pelapor' => 1, 'teknisi' => 2, 'admin' => 3][$role] ?? 0;
    }
}

if (!function_exists('tgResolveUser')) {
    // from.id Telegram -> user helpdesk (cek is_active). Return array user + tg info atau null.
    function tgResolveUser($conn, $telegramId)
    {
        try {
            $r = @pg_query_params($conn,
                "SELECT u.*, t.telegram_username AS tg_username, t.linked_via FROM telegram_identities t JOIN users u ON u.id = t.user_id WHERE t.telegram_id = $1 AND u.is_active = TRUE",
                [(int)$telegramId]);
            if ($r && pg_num_rows($r) > 0) {
                $u = pg_fetch_assoc($r);
                pg_free_result($r);
                return $u;
            }
            if ($r) pg_free_result($r);
        } catch (Throwable $e) {
        }
        return null;
    }
}

if (!function_exists('tgFindTelegramIdForUser')) {
    // user_id helpdesk -> telegram_id (untuk mention). Return int|null.
    function tgFindTelegramIdForUser($conn, $userId)
    {
        try {
            $r = @pg_query_params($conn, "SELECT telegram_id FROM telegram_identities WHERE user_id = $1", [(int)$userId]);
            if ($r && pg_num_rows($r) > 0) {
                $id = (int)pg_fetch_result($r, 0, 0);
                pg_free_result($r);
                return $id > 0 ? $id : null;
            }
            if ($r) pg_free_result($r);
        } catch (Throwable $e) {
        }
        return null;
    }
}

if (!function_exists('tgMention')) {
    // Mention klik-able yang memicu notif highlight walau user ganti username.
    function tgMention($telegramId, $name)
    {
        if (!$telegramId) return '<b>' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . '</b>';
        return '<a href="tg://user?id=' . (int)$telegramId . '">' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}

if (!function_exists('tgMenusFor')) {
    // Ambil menu root aktif yang boleh dilihat role. Cache 60 detik per-request agar dinamis tapi hemat.
    function tgMenusFor($conn, $role)
    {
        static $cache = [];
        $lvl = tgRoleLevel($role ?: 'guest');
        $key = 'r' . $lvl;
        if (isset($cache[$key]) && $cache[$key]['exp'] > time()) return $cache[$key]['rows'];
        $rows = [];
        try {
            $r = @pg_query($conn, "SELECT id, label, command, action, payload, min_role FROM telegram_menus WHERE is_active = TRUE AND parent_id IS NULL ORDER BY sort ASC, id ASC");
            if ($r) {
                while ($row = pg_fetch_assoc($r)) {
                    if (tgRoleLevel($row['min_role'] ?? 'guest') <= $lvl) $rows[] = $row;
                }
                pg_free_result($r);
            }
        } catch (Throwable $e) {
        }
        $cache[$key] = ['rows' => $rows, 'exp' => time() + 60];
        return $rows;
    }
}

if (!function_exists('tgKeyboardFromMenus')) {
    // 2 kolom per baris agar rapi di group.
    function tgKeyboardFromMenus(array $menus)
    {
        $kb = [];
        $row = [];
        foreach ($menus as $m) {
            $row[] = ['text' => $m['label'], 'callback_data' => 'menu:' . (int)$m['id']];
            if (count($row) === 2) {
                $kb[] = $row;
                $row = [];
            }
        }
        if ($row) $kb[] = $row;
        return $kb;
    }
}

if (!function_exists('tgLog')) {
    function tgLog($conn, $chatId, $tgId, $username, $command, $payload = null, $ok = null)
    {
        try {
            @pg_query_params($conn, "INSERT INTO telegram_logs (chat_id, telegram_id, username, command, payload, resp_ok) VALUES ($1,$2,$3,$4,$5,$6)",
                [$chatId ?: null, $tgId ?: null, $username, mb_substr((string)$command, 0, 100), $payload ? mb_substr((string)$payload, 0, 2000) : null, $ok]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('tgAllowedChats')) {
    // Daftar chat_id yang boleh menerima broadcast.
    function tgAllowedChats($conn)
    {
        $out = [];
        $c = tgConfig();
        if (!empty($c['default_chat'])) $out[] = $c['default_chat'];
        try {
            $r = @pg_query($conn, "SELECT chat_id FROM telegram_chats WHERE is_allowed = TRUE");
            if ($r) {
                while ($row = pg_fetch_assoc($r)) {
                    $id = trim((string)$row['chat_id']);
                    if ($id !== '' && !in_array($id, $out, true)) $out[] = $id;
                }
                pg_free_result($r);
            }
        } catch (Throwable $e) {
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('tgBroadcast')) {
    // Kirim ke semua chat allowlist. Non-fatal: return jumlah sukses.
    function tgBroadcast($text, $keyboard = null)
    {
        if (!tgEnabled()) return 0;
        $n = 0;
        try {
            $conn = getDBConnection();
            foreach (tgAllowedChats($conn) as $chatId) {
                if (tgSend($chatId, $text, $keyboard)) $n++;
            }
            pg_close($conn);
        } catch (Throwable $e) {
        }
        return $n;
    }
}

if (!function_exists('tgNotifyEvent')) {
    // Dipanggil dari ticket_action/cr_action/oncall setelah COMMIT. Aman bila tabel/bot belum siap.
    function tgNotifyEvent($text, $keyboard = null)
    {
        try {
            return tgBroadcast($text, $keyboard);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('tgAssigneeLine')) {
    // Baris "Ditangani: Budi" dengan mention bila Teknisi X sudah link.
    function tgAssigneeLine($conn, $userId)
    {
        if (empty($userId)) return 'Antrean (belum ada yang ambil)';
        try {
            $r = @pg_query_params($conn, "SELECT name FROM users WHERE id = $1", [(int)$userId]);
            $name = ($r && pg_num_rows($r) > 0) ? pg_fetch_result($r, 0, 0) : ('#' . (int)$userId);
            if ($r) pg_free_result($r);
            $tgId = tgFindTelegramIdForUser($conn, (int)$userId);
            return 'Ditangani: ' . tgMention($tgId, $name);
        } catch (Throwable $e) {
            return 'Ditangani: #' . (int)$userId;
        }
    }
}
