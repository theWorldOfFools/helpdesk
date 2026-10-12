# iOS WebView (WKWebView) — butuh Mac + Xcode

Bagian ini instruksi saja; build IPA tidak bisa dikerjakan dari server Linux.

## Implementasi minimal (Swift, ~40 baris)

- `WKWebViewConfiguration` + `WKWebsiteDataStore.default()` (cookie persisten → remember-me jalan).
- `WKUIDelegate` tidak perlu override khusus untuk `<input type=file>` modern — picker foto iOS muncul otomatis; pastikan `NSCameraUsageDescription` + `NSPhotoLibraryUsageDescription` di Info.plist.
- Link target eksternal: intercept di `decidePolicyFor navigationAction`, buka via `UIApplication.shared.open` bila host ≠ domain helpdesk.

## Checklist rilis

1. Xcode → App → WebView fullscreen, `app_url` = domain HTTPS produksi.
2. Info.plist: `NSCameraUsageDescription`, `NSPhotoLibraryUsageDescription` (wajib, kalau kosong Apple reject).
3. TestFlight internal (Apple Developer $99/thn) → bagikan ke tester via link.
4. App Store review: aplikasi "hanya webview" rawan ditolak Guideline 4.2 bila tanpa nilai native — mitigasi: tambah 1 fitur native kecil (mis. badge notif / share sheet) sebelum submit, atau tetap distribusikan via TestFlight internal + PWA publik.

## Rekomendasi praktis

Untuk iPhone user RS tahap 1: **PWA Add to Home Screen** (nol biaya, hari ini juga bisa). IPA native dijadwalkan bersama/Setelah Flutter tahap 2 agar sekalian lolos review.
