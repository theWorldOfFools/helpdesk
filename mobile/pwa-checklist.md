# Checklist Uji PWA di HP

## 0) Syarat server

- [ ] Helpdesk bisa dibuka dari HP via **HTTPS** (`https://domain/`, bukan localhost).
- [ ] Login halaman (`login.php`) terbuka, bukan redirect loop.

## 1) Android — Chrome

- [ ] Buka `https://domain/` → login sebagai pelapor.
- [ ] Menu ⋮ → **Add to Home screen** / **Install app**.
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
- [ ] Application → Service Workers: `sw.js` aktif; Cache Storage: `helpdesk-v2`.
