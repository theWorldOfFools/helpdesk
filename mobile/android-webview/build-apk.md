# Build APK WebView (Android Studio)

## Opsi A — tercepat, tanpa coding

1. Android Studio → New Project → **No Activity** (package `id.rs.helpdesk`).
2. Copy ke project: `MainActivity.kt` → `app/src/main/java/id/rs/helpdesk/`,
   `AndroidManifest.xml` → `app/src/main/`,
   `res-values-strings.xml` → `app/src/main/res/values/strings.xml`.
3. Tambah dependensi appcompat di `app/build.gradle` bila belum ada:
   `implementation("androidx.appcompat:appcompat:1.7.0")`
4. Ganti `app_url` di `strings.xml` dengan domain HTTPS produksi.
5. Run di emulator/HP (USB debugging) → dapat **APK debug** langsung bisa dicoba.

## Opsi B — APK release untuk dibagi

1. Build → Generate Signed Bundle/APK → buat keystore baru (simpan aman, bukan repo).
2. Hasil `app-release.apk` → bagikan via Drive internal / grup WA.
3. User Android 8+: izinkan "Install unknown apps" sekali saat install.

## Uji di HP (wajib)

- Login → tutup app → buka lagi → masih login (cookie WebView persisten).
- Buat tiket → lampirkan **foto kamera** → tersimpan (ini yang diuji `onShowFileChooser`).
- Tombol back HP = mundur halaman web, bukan keluar app.
- Link luar (mis. dokumentasi) terbuka di browser, bukan terjebak di WebView.

## Batasan

- HTTP (non-HTTPS) ditolak (`usesCleartextTraffic=false`) — sengaja, demi keamanan sesi.
- Push FCM native belum ada di tahap 1 (pakai notifikasi Telegram yang sudah jadi).
