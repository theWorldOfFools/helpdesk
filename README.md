# Helpdesk Sistem Tiket IT

Sistem tiket online untuk mencatat dan memantau operasional divisi IT dengan sistem RBAC (Role-Based Access Control).

## Fitur

- Pencatatan tiket operasional IT
- Monitoring status tiket (Open, In Progress, Resolved, Closed)
- Kategorisasi tiket (Jaringan, Hardware, Software, Lainnya)
- Prioritas tiket (Low, Medium, High, Critical)
- Dashboard monitoring real-time
- Sistem RBAC dengan 3 role: Administrator, Teknisi, Pelapor
- Autentikasi login dengan session management
- Filter dan pencarian tiket

## Role & Akses

| Fitur | Administrator | Teknisi | Pelapor |
|---|---|---|---|
| Buat Tiket | ✅ | ✅ | ✅ |
| Lihat Semua Tiket | ✅ | ✅ | ❌ (Own only) |
| Edit Tiket | ✅ | ✅ | ❌ |
| Hapus Tiket | ✅ | ❌ | ❌ |
| Buat CR | ✅ | ✅ | ✅ |
| Lihat Semua CR | ✅ | ✅ | ❌ (Own only) |
| Edit/Hapus CR | ✅ | ❌ | ❌ |
| Tindak Lanjut CR | ✅ | ✅ | 💬 (Komentar own only) |
| Dashboard Penuh | ✅ | ✅ | ✅ (Own only) |
| Kelola User | ✅ | ❌ | ❌ |

## Arsitektur

```
┌─────────┐     ┌──────────┐     ┌──────────┐     ┌──────────────┐
│  User   │────▶│  PHP     │────▶│PostgreSQL│────▶│  Dashboard   │
│(Browser)│     │  Backend │     │  DB      │     │  Monitoring  │
└─────────┘     └──────────┘     └──────────┘     └──────────────┘
      ▲              │
      │              ▼
      │        ┌──────────┐
      └────────│   View   │
               │  (HTML)  │
               └──────────┘
```

**Flow Request:**
1. User mengakses halaman melalui browser (login required)
2. Request diterima oleh PHP Controller
3. Middleware autentikasi (auth.php) memeriksa role
4. Controller berinteraksi dengan PostgreSQL melalui config.php
5. Response ditampilkan di View (HTML + CSS + JS)

## Prasyarat

- PHP 8.0 ke atas (teruji 8.2)
- PostgreSQL 12 ke atas
- Web server (Apache/Nginx) atau `php -S`
- Ekstensi PHP: `pgsql`, `mbstring`

## Instalasi

### 1. Clone Repository

```bash
cd /var/www/html
git clone <repository-url> helpdesk
cd helpdesk
```

### 2. Konfigurasi Env

```bash
cp .env.example .env
# sesuaikan DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS
```

### 3. Konfigurasi Database

Buat database PostgreSQL dan import schema:

```bash
sudo -u postgres psql
CREATE DATABASE helpdesk_db;
\c helpdesk_db
\i db/schema.sql
```

Atau import migrasi (berurutan):

```bash
psql -U postgres -d helpdesk_db -f db/schema.sql
psql -U postgres -d helpdesk_db -f migrations/001_initial_schema.sql
psql -U postgres -d helpdesk_db -f migrations/002_rbac_schema.sql
psql -U postgres -d helpdesk_db -f migrations/003_ticket_attachments.sql
psql -U postgres -d helpdesk_db -f migrations/004_assignment_sla.sql
psql -U postgres -d helpdesk_db -f migrations/005_comments_history.sql
psql -U postgres -d helpdesk_db -f migrations/006_login_attempts.sql
psql -U postgres -d helpdesk_db -f migrations/007_sla_working_hours.sql
psql -U postgres -d helpdesk_db -f migrations/008_account.sql
psql -U postgres -d helpdesk_db -f migrations/009_kb.sql
psql -U postgres -d helpdesk_db -f migrations/010_settings_notifications.sql
psql -U postgres -d helpdesk_db -f migrations/011_change_requests.sql
psql -U postgres -d helpdesk_db -f migrations/012_change_request_comments.sql
```

> Catatan: migrasi wajib dijalankan berurutan 001→012 dari schema kosong.
> `011_change_requests.sql` dan `012_change_request_comments.sql` idempoten (`IF NOT EXISTS`) sehingga aman
> dijalankan ulang di database lama. Setelah migrasi, buka form di
> `create_cr.php` (login dulu) dan pastikan folder
> `uploads/change_requests/` writable.

### 3b. Install Dependensi PHP (untuk export XLSX/PDF)

```bash
composer install
```

### 4. Login Default

Setelah instalasi, login dengan akun default:

| Username | Password | Role |
|---|---|---|
| admin | admin123 | Administrator |
| teknisi1 | admin123 | Teknisi |
| pelapor1 | admin123 | Pelapor |

### 5. Jalankan Aplikasi

Jika menggunakan PHP built-in server:

```bash
cd /var/www/html/helpdesk
php -S localhost:8080
```

Buka browser ke `http://localhost:8080` dan login terlebih dahulu.

Atau konfigurasi virtual host di Apache/Nginx dengan document root ke `/var/www/html/helpdesk`.

## Struktur Project

```
helpdesk/
├── README.md
├── config.php              # Koneksi database PostgreSQL & auth functions
├── login.php               # Halaman login
├── logout.php              # Logout
├── index.php               # Daftar tiket (DataTables server-side + fallback PHP)
├── create_ticket.php       # Form tambah tiket
├── create_cr.php           # Form Change Request (header + tabel dinamis Jenis Perubahan)
├── cr_list.php             # Daftar CR terpisah (filter + kartu status + DataTables + fallback PHP)
├── view_cr.php             # Detail CR + Tindak Lanjut (tombol aksi) + diskusi/riwayat
├── edit_cr.php             # Edit CR (admin only, termasuk rincian item)
├── delete_cr.php           # Hapus CR via POST+CSRF (admin only)
├── cr_action.php           # Backend tindak lanjut CR (fleksibel + wajib catatan)
├── view_ticket.php         # Detail + Tindak Lanjut (tombol aksi) + diskusi/riwayat
├── edit_ticket.php         # Edit data (admin only)
├── delete_ticket.php       # Hapus tiket via POST+CSRF (admin only)
├── ticket_action.php       # Backend tindak lanjut (fleksibel + wajib catatan)
├── dashboard.php           # Dashboard per-role + Chart.js + leaderboard bulanan
├── user_management.php     # Kelola pengguna + reset PW + log aktivitas (admin)
├── profile.php             # Profil + ganti password (semua role)
├── kb.php                  # Basis solusi (semua role)
├── kb_manage.php           # Kelola artikel + template jawaban (admin/teknisi)
├── notifications.php         # Pusat notifikasi in-app (semua role)
├── manifest.json + sw.js     # PWA dasar (instalable, cache aset statis)
├── api/
│   ├── tickets.php         # DataSource DataTables tiket (server paging/sort/filter)
│   ├── crs.php             # DataSource DataTables CR (server paging/sort/filter)
│   ├── users.php           # DataSource Grid users (admin)
│   ├── dashboard.php       # JSON chart + leaderboard (trend/status/workload)
│   └── reports.php         # JSON laporan (kpi/trend/sla_response/sla_resolution/breach)
├── export.php              # Export csv/xlsx/pdf sesuai filter + scope role
├── db/
│   └── schema.sql          # Schema database (users + tickets)
├── migrations/
│   ├── 001_initial_schema.sql
│   ├── 002_rbac_schema.sql
│   ├── 003_ticket_attachments.sql
│   ├── 004_assignment_sla.sql   # assigned_to, sla_due_at, resolved_at, closed_at
│   ├── 005_comments_history.sql # ticket_comments + ticket_history
│   ├── 006_login_attempts.sql   # rate-limit login
│   ├── 007_sla_working_hours.sql # holidays + working_minutes()/next_working_start()
│   ├── 008_account.sql          # remember_token, must_change_password, activity_log
│   ├── 009_kb.sql               # kb_articles + kb_templates (seed)
│   ├── 010_settings_notifications.sql # settings target SLA + notifications
│   ├── 011_change_requests.sql  # change_requests + items + history (header + rincian CR)
│   └── 012_change_request_comments.sql # change_request_comments (diskusi CR)
├── includes/
│   ├── auth.php            # Auth middleware & permission functions
│   ├── cr_auth.php         # Helper CR: generateCrNumber/canViewCr/canActOnCr/upload
│   ├── csrf.php            # CSRF token/verify
│   ├── env.php             # Loader .env minimal
│   ├── header.php          # Template + DataTables/Chart.js CDN
│   └── footer.php          # Template + datatables-grids.js/dashboard-charts.js/reports.js
└── assets/
    ├── css/style.css
    └── js/
        ├── script.js
        ├── datatables-grids.js   # DataTables tiket/users/divisi/workload
        ├── dashboard-charts.js  # Chart.js + leaderboard fetch
        └── reports.js           # Menu laporan fetch + analisa
```

## Konfigurasi RBAC

Sistem menggunakan 3 role:

- **Administrator**: Akses penuh + Edit data + semua aksi + manajemen pengguna + hapus tiket/CR
- **Teknisi**: Tindak lanjut tiket/CR via tombol aksi (fleksibel open↔in_progress↔resolved↔closed, wajib catatan), tanpa Edit data
- **Pelapor**: Buat tiket/CR + lihat/komentar tiket/CR sendiri saja

## Fitur Baru

- Tindak lanjut terpisah dari Edit (`ticket_action.php`), audit `ticket_history` + `ticket_comments`
- Assignment teknisi + SLA jam kerja Senin–Sabtu 08:00–17:00 (`sla_due_at`, badge overdue, MTTR jam kerja)
- SLA respons ≤ 60 menit kerja (numerator/denominator, target 100%) + widget peringatan teknisi
- Menu Laporan eksekutif: KPI, analisa otomatis, tren, SLA respons/resolusi, breach list, kelola hari libur, export CSV/XLSX/PDF
- Modul akun: profil + ganti password, remember-me fungsional, wajib ganti password default, reset oleh admin, log aktivitas
- Knowledge base: artikel solusi + template jawaban sekali klik di modal tindak lanjut
- Target SLA & respons jadi setting DB (diubah admin via menu Laporan, tanpa coding)
- Notifikasi in-app: bell + halaman, terpicu saat tiket dibuat/diubah
- PWA dasar: manifest + ikon + service worker (cache aset statis)
- DataTables server-side di semua tabel (fallback PHP bila CDN offline)
- Dashboard Chart.js per-role + leaderboard teknisi (skor cepat+banyak) & pelapor (bulanan)
- Change Request end-to-end (terpisah dari tiket): buat + tabel dinamis Jenis Perubahan (Penambahan/Perubahan/Design, 1–20 baris), daftar + API server-side, detail + tindak lanjut + diskusi/riwayat, edit/hapus admin, nomor CR-YYYYMM-XXXX anti-race, SLA jam kerja, notifikasi, widget dashboard + laporan + export
- Security: `.env`, CSRF semua POST, delete via POST, rate-limit login, session regenerate, ticket number anti-race

### Menambah Pengguna Baru

Masuk sebagai administrator, lalu jalankan query SQL:

```sql
INSERT INTO users (username, password, name, role, division)
VALUES ('newuser', 'hashed_password', 'Nama Lengkap', 'teknisi', 'IT Support');
```

Gunakan `password_hash('password', PASSWORD_BCRYPT)` untuk generate hash password.

## Kontribusi

Silakan fork dan kirim pull request. Semua kontribusi sangat diapresiasi!

## Lisensi

MIT License
