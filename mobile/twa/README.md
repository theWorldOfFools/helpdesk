# TWA → Play Store (Bubblewrap)

PWA kamu dibungkus jadi AAB tanpa coding. Prasyarat: Node.js 18+, JDK 17, `APP_URL` HTTPS publik.

## Langkah

1. Install Bubblewrap: `npm i -g @bubblewrap/cli` lalu `bubblewrap doctor` (penuhi yang merah).
2. Copy `twa-manifest.json` ke folder kerja, ganti `ganti-dengan-domain-kamu.example` dengan domain asli (2 tempat: `host`, `iconUrl`).
3. Generate keystore SEKALI (simpan aman, jangan commit):
   `keytool -genkey -v -keystore android.keystore -alias helpdesk -keyalg RSA -keysize 2048 -validity 10000`
4. Ambil fingerprint:
   `keytool -list -v -keystore android.keystore -alias helpdesk | grep SHA256`
5. Salin `assetlinks.template.json` → isi fingerprint → deploy sebagai:
   `https://domain-kamu/.well-known/assetlinks.json` (repo ini BELUM menaruhnya live on purpose — file placeholder yang ke-serve justru merusak verifikasi).
6. Verifikasi serve: `curl -s https://domain-kamu/.well-known/assetlinks.json` harus 200 + `Content-Type: application/json`.
7. Build: `bubblewrap init --manifest=twa-manifest.json` lalu `bubblewrap build`.
8. Upload `app-release-bundle.aab` ke Play Console ($25 sekali bayar) → internal testing → production.

## Catatan

- TWA **hanya Android**. iPhone tetap via PWA Add to Home Screen.
- Ganti keystore = ganti aplikasi di mata Play Store. Backup `android.keystore` + password di tempat aman (bukan repo).
- `signingKey.passwords` sengaja tidak diisi di manifest — masukkan saat prompt build.
