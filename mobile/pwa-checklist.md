# Checklist Uji PWA di HP

## 0) Syarat server

- [ ] Helpdesk bisa dibuka dari HP via **HTTPS** (`https://domain/`, bukan localhost).
- [ ] Login halaman (`login.php`) terbuka, bukan redirect loop.

## 1) Android — Chrome

- [ ] Buka `https://domain/` (wajib HTTPS; `http://IP` tidak bisa install) → login sebagai pelapor.
- [ ] Tombol **Pasang Aplikasi** di sidebar/login muncul → klik → prompt Install native.
- [ ] Fallback: menu ⋮ → **Install app** / **Add to Home screen**.
- [ ] Buka dari ikon: tampil fullscreen tanpa address bar (mode standalone).
- [ ] Buat tiket + lampirkan foto dari kamera → tersimpan di `uploads/tickets/`.
- [ ] Tutup total → buka lagi → masih login (remember-me; atau login ulang bila sesi habis).
- [ ] Putar HP (portrait) + buka sidebar (☰) → menu teknisi/pelapor benar per role.
- [ ] Matikan internet → buka ikon → halaman dinamis gagal wajar (aset statis tetap tampil). Ini ekspektasi tahap 1.

## 2) iPhone — Safari (wajib Safari, Chrome iOS tidak bisa install PWA)

- [ ] Buka `https://domain/` → login.
- [ ] Share → **Add to Home Screen** → buka dari ikon.
- [ ] Notifikasi bell in-app jalan; push iOS native belum ada di tahap 1 (pakai Telegram).

## 3) Long-press ikon (shortcuts, Android)

- [ ] Tahan ikon Helpdesk IT → muncul **Buat Tiket** dan **Jadwal Oncall**.
- [ ] Ketuk masing-masing → langsung ke halaman yang benar (login dulu bila sesi habis).

## 4) DevTools (tanpa HP fisik)

- [ ] Chrome laptop → DevTools → Application → Manifest: ikon 192/512 ✅, shortcuts ✅.
- [ ] Lighthouse → kategori PWA: target **Installable** centang hijau.
- [ ] Application → Service Workers: `sw.js` aktif; Cache Storage: `helpdesk-v3`.

## 5) Troubleshooting install Android

- [ ] Menu **Install app** tidak muncul / tombol hanya menampilkan panduan = halaman bukan secure context.
      Cek address bar harus `https://` berlogo gembok. `http://IP-LAN` **tidak bisa install PWA** (Chrome blokir service worker).
- [ ] Solusi permanen: aktifkan HTTPS di server (domain + Let's Encrypt, atau sertifikat internal + CA di-trust di HP).
- [ ] Solusi uji cepat (HP sendiri): `chrome://flags/#unsafely-treat-insecure-origin-as-secure` → isi alamat situs → **Enabled** → restart Chrome.
- [ ] Setelah HTTPS aktif, muat ulang halaman; `beforeinstallprompt` memunculkan tombol **Pasang Aplikasi**.

## 6) Server produksi (reverse proxy)

Semua URL di bawah harus **200** dari luar, dari base URL yang sama dengan halaman login:

- [ ] `curl -I https://domain/login.php` → 200
- [ ] `curl -I https://domain/manifest.json` → 200 (JSON)
- [ ] `curl -I https://domain/sw.js` → 200 (`text/javascript`, bukan halaman 404 Apache)
- [ ] `curl -I https://domain/assets/icons/icon-192.png` dan `assets/icons/icon-512.png` → 200 (`image/png`)

Gejala proxy "terbelah" (PHP/manifest di root, `sw.js`/`assets/` hanya di subfolder):

- Manifest termuat tapi `/assets/icons/*` 404 → Chrome gagal mengambil ikon.
- SW didaftarkan relatif (`sw.js`) tapi `/sw.js` 404 → service worker tidak pernah aktif.
- Ditambah cakupan `start_url`/scope tidak konsisten → instal berakhir dengan notifikasi Chrome **"Tidak dapat menginstal ..."** (kegagalan WebAPK minting di sisi Google: `IDS_NOTIFICATION_WEBAPK_INSTALL_FAILED`).

Perbaikan: samakan base URL 1 server — halaman, `manifest.json`, `sw.js`, dan `assets/` semua di root domain yang sama. Deploy file repo apa adanya (path aset relatif); jangan menambal path jadi absolut `/subfolder/...` karena memecah scope service worker.

Contoh **Advanced** di Nginx Proxy Manager bila aplikasi upstream ada di subfolder `/helpdesk/` (root domain dipetakan ke `/helpdesk/`):

```nginx
location /helpdesk/ {
    proxy_pass http://<IP-APP>:8181;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
location / {
    proxy_pass http://<IP-APP>:8181/helpdesk/;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_redirect http://<IP-APP>:8181/helpdesk/ /;
    proxy_redirect http://<IP-APP>:8181/ /;
}
```

File config siap-paste: `docs/npm-advanced-helpdesk.txt`.

Dua jebakan yang bikin `/sw.js` + `/assets/*` 404 walau upstream sehat:

- `sub_filter` untuk rewrite aset (`/assets/` → `/helpdesk/assets/`) — halaman sudah memakai path relatif; rewrite ini membuat proxy menambah prefix dua kali → `/helpdesk/helpdesk/assets/...` → 404. Jangan pakai; hapus juga `proxy_set_header Accept-Encoding ""` (hanya perlu untuk sub_filter).
- Custom Location regex ekstensi statis (`js|css|png|...`) yang proxy tanpa prefix `/helpdesk/` — regex location dievaluasi sebelum `location /`, sehingga `sw.js`/`assets/` nyasar ke root upstream (404) sementara `manifest.json` (json tidak masuk regex) tetap 200. Pastikan tab **Custom Locations** kosong.

Setelah proxy benar: uninstall shortcut lama → Chrome → pengaturan situs → **Hapus & setel ulang** → buka ulang → **Install app**.

Catatan: `APP_URL` di `.env` **tidak** memengaruhi install PWA; nilainya hanya dipakai untuk menyusun link di pesan Telegram.
