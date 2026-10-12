# Mobile — Helpdesk IT

Satu folder untuk semua jalur mobile. **Backend PHP tidak diubah** di tahap 1.

## Pilih jalur (ringkas)

| Jalur | Hasil | Cocok untuk | Mulai dari |
|---|---|---|---|
| **PWA** (nol build) | Install dari browser, Android + iPhone | Coba hari ini di HP | `pwa-checklist.md` |
| **TWA** (Bubblewrap) | AAB/APK Play Store dari PWA | Rilis resmi Android | `twa/` |
| **WebView APK** | APK internal dari web yang ada | Bagi APK via grup/WA | `android-webview/` |
| **iOS WebView** | IPA via Xcode (butuh Mac) | iPhone native | `ios-webview/` |
| **Flutter** | Tahap 2 (butuh `api/v1` + token auth) | FCM/offline/UX native | kontrak API menyusul |

## Coba di HP (paling cepat → PWA)

**Android (Chrome):** buka `https://domain-kamu/` → login → menu ⋮ → **Add to Home screen / Install app** → ikon Helpdesk IT muncul, jalan fullscreen (standalone).

**iPhone (Safari, wajib Safari):** buka `https://domain-kamu/` → login → Share → **Add to Home Screen** → buka dari ikon.

Syarat: **HTTPS wajib** (HTTP localhost tidak bisa install di HP fisik). Kalau server masih `http://localhost:8080`, expose dulu via reverse proxy / tunnel HTTPS, atau tes di Chrome laptop via DevTools → Application → Manifest.

## Struktur

```
mobile/
  README.md               # file ini
  pwa-checklist.md        # checklist uji PWA di HP
  twa/
    twa-manifest.json     # config Bubblewrap (sesuaikan host + warna)
    assetlinks.template.json  # isi fingerprint → taruh di .well-known/
    README.md             # langkah build AAB + verifikasi
  android-webview/
    MainActivity.kt       # wrapper WebView + file-upload/kamera
    AndroidManifest.xml   # permission + activity
    res-values-strings.xml# APP_URL terpusat (satu tempat ganti domain)
    build-apk.md          # build APK debug/release
  ios-webview/
    README.md             # WKWebView + checklist Xcode (butuh Mac)
```

## Keputusan yang sudah diambil (tahap 1)

- 1 codebase tetap PHP; tidak ada duplikasi logika tiket/RBAC/SLA.
- Session + remember-me cookie jalan apa adanya di PWA/TWA/WebView.
- Push native (FCM) dan offline antrean = tahap 2 (Flutter + `api/v1`).
