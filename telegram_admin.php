<?php
// Telegram Admin: config + menu dinamis + allowlist chat + tautan manual + log. Admin only.
require_once 'config.php';
require_once __DIR__ . '/includes/telegram.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    redirectByRole($user['role'] ?? 'pelapor');
}
$pageTitle = 'Telegram Admin';
require_once 'includes/header.php';
$conn = getDBConnection();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'cfg') {
            $en = isset($_POST['telegram_enabled']) ? '1' : '0';
            $chat = trim($_POST['default_chat'] ?? '');
            @pg_query_params($conn, "INSERT INTO settings (kunci, nilai, keterangan) VALUES ('telegram_enabled',$1,'1 = bot aktif kirim notifikasi') ON CONFLICT (kunci) DO UPDATE SET nilai = EXCLUDED.nilai", [$en]);
            @pg_query_params($conn, "INSERT INTO settings (kunci, nilai, keterangan) VALUES ('telegram_default_chat_id',$1,'chat_id group utama') ON CONFLICT (kunci) DO UPDATE SET nilai = EXCLUDED.nilai", [$chat]);
            $buser = trim($_POST['bot_username'] ?? '');
            @pg_query_params($conn, "INSERT INTO settings (kunci, nilai, keterangan) VALUES ('telegram_bot_username',$1,'username bot tanpa @') ON CONFLICT (kunci) DO UPDATE SET nilai = EXCLUDED.nilai", [$buser]);
            logActivity($conn, $user['id'], 'telegram_cfg', "enabled=$en chat=$chat");
            $msg = 'Konfigurasi disimpan. Token & secret tetap via .env di server.';
        } elseif ($act === 'set_webhook') {
            $c = tgConfig();
            $app = rtrim((string)env('APP_URL', ''), '/');
            if ($app === '' || $c['secret'] === '') {
                throw new Exception('Isi APP_URL dan TELEGRAM_WEBHOOK_SECRET di .env dulu.');
            }
            $url = $app . '/bot_webhook.php?secret=' . urlencode($c['secret']);
            $res = tgApi('setWebhook', ['url' => $url]);
            if (!$res) throw new Exception('setWebhook gagal. Cek token & HTTPS publik.');
            logActivity($conn, $user['id'], 'telegram_webhook', $url);
            $msg = 'Webhook dipasang: ' . $url;
        } elseif ($act === 'test_kirim') {
            $chat = trim($_POST['chat_id'] ?? tgConfig()['default_chat']);
            if ($chat === '') throw new Exception('Isi chat_id dulu.');
            $ok = tgSend($chat, "✅ <b>Tes bot Helpdesk IT</b>\n" . date('d M Y H:i') . " — notifikasi jalan.");
            if (!$ok) throw new Exception('Gagal kirim. Cek token, chat_id, dan bot sudah jadi admin group.');
            $msg = 'Tes terkirim ke ' . $chat;
        } elseif ($act === 'menu_add') {
            $label = trim($_POST['label'] ?? '');
            $command = trim($_POST['command'] ?? '');
            $action = trim($_POST['action'] ?? 'menu');
            $minRole = trim($_POST['min_role'] ?? 'guest');
            $sort = (int)($_POST['sort'] ?? 0);
            $parent = (int)($_POST['parent_id'] ?? 0);
            if ($label === '' || $action === '') throw new Exception('Label & action wajib.');
            if (!in_array($minRole, ['guest', 'pelapor', 'teknisi', 'admin'], true)) $minRole = 'guest';
            @pg_query_params($conn, "INSERT INTO telegram_menus (parent_id, label, command, action, min_role, sort) VALUES ($1,$2,$3,$4,$5,$6)",
                [$parent > 0 ? $parent : null, $label, $command, $action, $minRole, $sort]);
            $msg = 'Menu ditambah.';
        } elseif ($act === 'menu_toggle') {
            @pg_query_params($conn, "UPDATE telegram_menus SET is_active = NOT is_active WHERE id = $1", [(int)($_POST['id'] ?? 0)]);
            $msg = 'Status menu diubah.';
        } elseif ($act === 'menu_del') {
            @pg_query_params($conn, "DELETE FROM telegram_menus WHERE id = $1", [(int)($_POST['id'] ?? 0)]);
            $msg = 'Menu dihapus.';
        } elseif ($act === 'chat_toggle') {
            @pg_query_params($conn, "UPDATE telegram_chats SET is_allowed = NOT is_allowed WHERE chat_id = $1", [(int)($_POST['chat_id'] ?? 0)]);
            $msg = 'Status chat diubah.';
        } elseif ($act === 'link_manual') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $tgid = (int)($_POST['telegram_id'] ?? 0);
            $tgun = trim($_POST['telegram_username'] ?? '');
            if ($uid <= 0 || $tgid <= 0) throw new Exception('user_id & telegram_id wajib angka.');
            $chk = pg_query_params($conn, "SELECT id FROM users WHERE id = $1 AND is_active = TRUE", [$uid]);
            if (!$chk || pg_num_rows($chk) === 0) throw new Exception('User tidak valid.');
            if ($chk) pg_free_result($chk);
            @pg_query($conn, 'BEGIN');
            @pg_query_params($conn, "DELETE FROM telegram_identities WHERE user_id = $1 OR telegram_id = $2", [$uid, $tgid]);
            $ins = @pg_query_params($conn, "INSERT INTO telegram_identities (user_id, telegram_id, telegram_username, linked_via) VALUES ($1,$2,$3,'manual')", [$uid, $tgid, $tgun !== '' ? $tgun : null]);
            if (!$ins) {
                @pg_query($conn, 'ROLLBACK');
                throw new Exception('Gagal simpan (mungkin duplikat).');
            }
            @pg_query($conn, 'COMMIT');
            logActivity($conn, $user['id'], 'telegram_link_manual', "user=$uid tg=$tgid");
            $msg = 'Tautan manual tersimpan.';
        } elseif ($act === 'unlink') {
            @pg_query_params($conn, "DELETE FROM telegram_identities WHERE user_id = $1", [(int)($_POST['user_id'] ?? 0)]);
            $msg = 'Tautan diputus.';
        }
    } catch (Exception $ex) {
        $msg = $ex->getMessage();
        $msgType = 'danger';
    }
}

$cfg = tgConfig();
$menus = [];
$r = @pg_query($conn, "SELECT * FROM telegram_menus ORDER BY COALESCE(parent_id,0), sort, id");
if ($r) {
    while ($x = pg_fetch_assoc($r)) $menus[] = $x;
    pg_free_result($r);
}
$chats = [];
$r = @pg_query($conn, "SELECT * FROM telegram_chats ORDER BY created_at DESC LIMIT 20");
if ($r) {
    while ($x = pg_fetch_assoc($r)) $chats[] = $x;
    pg_free_result($r);
}
$links = [];
$r = @pg_query($conn, "SELECT t.*, u.name, u.username AS uname, u.role FROM telegram_identities t JOIN users u ON u.id = t.user_id ORDER BY t.linked_at DESC LIMIT 50");
if ($r) {
    while ($x = pg_fetch_assoc($r)) $links[] = $x;
    pg_free_result($r);
}
$logs = [];
$r = @pg_query($conn, "SELECT * FROM telegram_logs ORDER BY id DESC LIMIT 30");
if ($r) {
    while ($x = pg_fetch_assoc($r)) $logs[] = $x;
    pg_free_result($r);
}
$users = [];
$r = @pg_query($conn, "SELECT id, name, username, role FROM users WHERE is_active = TRUE ORDER BY name ASC LIMIT 200");
if ($r) {
    while ($x = pg_fetch_assoc($r)) $users[] = $x;
    pg_free_result($r);
}
$actions = ['tiket_open' => 'Tiket open', 'cr_aktif' => 'CR aktif', 'tiket_saya' => 'Tiket saya', 'antrean' => 'Antrean divisi', 'oncall_today' => 'Oncall hari ini', 'report_daily' => 'Laporan harian', 'kb_search' => 'Cari KB (pakai /kb kata)', 'kb_hint' => 'Hint KB', 'help' => 'Bantuan', 'menu' => 'Submenu saja'];
?>

<h1 class="h4 mb-3">🤖 Telegram Admin</h1>
<?php if ($msg): ?><div class="alert alert-<?php echo $msgType; ?>"><?php echo e($msg); ?></div><?php endif; ?>
<div class="alert alert-secondary small">Token &amp; secret baca dari <code>.env</code> (<code>TELEGRAM_BOT_TOKEN</code>, <code>TELEGRAM_WEBHOOK_SECRET</code>). BotFather: <code>/setprivacy → Disable</code> + jadikan bot admin group. Webhook: <code><?php echo e(rtrim((string)env('APP_URL', ''), '/')); ?>/bot_webhook.php?secret=XXX</code></div>

<div class="row g-3">
<div class="col-lg-6"><div class="card"><div class="card-body">
    <h3 class="h6">Status &amp; Webhook</h3>
    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="act" value="cfg">
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="tgEn" name="telegram_enabled" <?php echo $cfg['enabled'] ? 'checked' : ''; ?>><label class="form-check-label" for="tgEn">Bot aktif (kirim notifikasi)</label></div>
        <div class="mb-2"><label class="form-label">Default chat_id group</label><input class="form-control" name="default_chat" value="<?php echo e($cfg['default_chat']); ?>" placeholder="-100xxxx"></div>
        <div class="mb-2"><label class="form-label">Bot username (tanpa @)</label><input class="form-control" name="bot_username" value="<?php echo e($cfg['username']); ?>" placeholder="helpdesk_it_bot"></div>
        <button class="btn btn-sm btn-primary" type="submit">Simpan</button>
    </form>
    <hr>
    <form method="POST" class="d-flex gap-2 flex-wrap"><?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="set_webhook"><button class="btn btn-sm btn-dark" type="submit">Pasang Webhook</button>
    </form>
    <form method="POST" class="d-flex gap-2 mt-2"><?php echo csrf_field(); ?><input type="hidden" name="act" value="test_kirim">
        <input class="form-control form-control-sm" name="chat_id" placeholder="chat_id (kosong = default)" value="<?php echo e($cfg['default_chat']); ?>">
        <button class="btn btn-sm btn-success" type="submit">Test Kirim</button>
    </form>
</div></div></div>

<div class="col-lg-6"><div class="card"><div class="card-body">
    <h3 class="h6">Tambah Menu</h3>
    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="act" value="menu_add">
        <div class="row g-2">
            <div class="col-6"><input class="form-control form-control-sm" name="label" required maxlength="80" placeholder="Label 🎫 Tiket Open"></div>
            <div class="col-6"><input class="form-control form-control-sm" name="command" maxlength="40" placeholder="/tiket_open"></div>
            <div class="col-6"><select class="form-select form-select-sm" name="action"><?php foreach ($actions as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo e($v); ?></option><?php endforeach; ?></select></div>
            <div class="col-3"><select class="form-select form-select-sm" name="min_role"><option value="guest">guest</option><option value="pelapor">pelapor</option><option value="teknisi">teknisi</option><option value="admin">admin</option></select></div>
            <div class="col-3"><input class="form-control form-control-sm" type="number" name="sort" value="10" title="urutan"></div>
        </div>
        <button class="btn btn-sm btn-primary mt-2" type="submit">Tambah</button>
        <span class="small text-secondary ms-2">Edit lanjutan langsung di DB bila perlu tree parent.</span>
    </form>
</div></div></div>
</div>

<div class="card mt-3"><div class="card-body">
    <h3 class="h6">Menu Global (disaring per-role oleh webhook)</h3>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead class="table-dark"><tr><th>ID</th><th>Label</th><th>Action</th><th>Min Role</th><th>Sort</th><th>Aktif</th><th></th></tr></thead>
        <tbody><?php foreach ($menus as $m): ?><tr>
            <td><?php echo $m['id']; ?></td><td><?php echo e($m['label']); ?> <small class="text-secondary"><?php echo e($m['command']); ?></small></td>
            <td><code><?php echo e($m['action']); ?></code></td><td><?php echo e($m['min_role']); ?></td><td><?php echo $m['sort']; ?></td>
            <td><?php echo ($m['is_active'] === 't' || $m['is_active'] == 1) ? '✅' : '❌'; ?></td>
            <td class="text-nowrap"><form method="POST" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="act" value="menu_toggle"><input type="hidden" name="id" value="<?php echo $m['id']; ?>"><button class="btn btn-sm btn-outline-secondary">On/Off</button></form>
            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus menu?')"><?php echo csrf_field(); ?><input type="hidden" name="act" value="menu_del"><input type="hidden" name="id" value="<?php echo $m['id']; ?>"><button class="btn btn-sm btn-outline-danger">Hapus</button></form></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
</div></div>

<div class="row g-3 mt-1">
<div class="col-lg-6"><div class="card"><div class="card-body">
    <h3 class="h6">Chat Allowlist (auto-terdaftar saat bot disapa)</h3>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-dark"><tr><th>chat_id</th><th>Judul</th><th>Izin</th><th></th></tr></thead>
        <tbody><?php foreach ($chats as $c): ?><tr><td><code><?php echo e($c['chat_id']); ?></code></td><td><?php echo e($c['title'] ?? '-'); ?></td>
        <td><?php echo ($c['is_allowed'] === 't' || $c['is_allowed'] == 1) ? '✅' : '❌'; ?></td>
        <td><form method="POST" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="act" value="chat_toggle"><input type="hidden" name="chat_id" value="<?php echo e($c['chat_id']); ?>"><button class="btn btn-sm btn-outline-secondary">On/Off</button></form></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div></div></div>

<div class="col-lg-6"><div class="card"><div class="card-body">
    <h3 class="h6">Tautan Manual (cadangan OTP)</h3>
    <form method="POST" class="row g-2"><?php echo csrf_field(); ?><input type="hidden" name="act" value="link_manual">
        <div class="col-6"><select class="form-select form-select-sm" name="user_id" required><option value="">— user —</option><?php foreach ($users as $u): ?><option value="<?php echo $u['id']; ?>"><?php echo e($u['name']); ?> · <?php echo e($u['role']); ?></option><?php endforeach; ?></select></div>
        <div class="col-3"><input class="form-control form-control-sm" name="telegram_id" required placeholder="telegram_id"></div>
        <div class="col-3"><input class="form-control form-control-sm" name="telegram_username" placeholder="@user"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Tautkan manual</button> <span class="small text-secondary">Cara tahu telegram_id: user kirim <code>/siapa</code> ke bot, lihat log di bawah.</span></div>
    </form>
    <hr>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-dark"><tr><th>User</th><th>tg_id</th><th>Via</th><th></th></tr></thead>
        <tbody><?php foreach ($links as $l): ?><tr><td><?php echo e($l['name']); ?> <small>(<?php echo e($l['role']); ?>)</small></td><td><code><?php echo e($l['telegram_id']); ?></code></td><td><?php echo e($l['linked_via']); ?></td>
        <td><form method="POST" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="act" value="unlink"><input type="hidden" name="user_id" value="<?php echo $l['user_id']; ?>"><button class="btn btn-sm btn-outline-danger">Putus</button></form></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div></div></div>
</div>

<div class="card mt-3"><div class="card-body">
    <h3 class="h6">Log Terakhir</h3>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="table-dark"><tr><th>Waktu</th><th>chat</th><th>tg user</th><th>command</th><th>OK</th></tr></thead>
        <tbody><?php foreach ($logs as $l): ?><tr><td class="text-nowrap"><?php echo e($l['created_at']); ?></td><td><code><?php echo e($l['chat_id']); ?></code></td><td><?php echo e($l['username'] ?? $l['telegram_id']); ?></td><td><code><?php echo e(mb_substr($l['command'] ?? '', 0, 60)); ?></code></td><td><?php echo $l['resp_ok'] === null ? '-' : (($l['resp_ok'] === 't' || $l['resp_ok'] == 1) ? '✅' : '❌'); ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div></div>

<?php pg_close($conn); ?>
<?php require_once 'includes/footer.php'; ?>
