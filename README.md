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

- PHP 7.4 ke atas
- PostgreSQL 12 ke atas
- Web server (Apache/Nginx)
- Ekstensi PHP: `pgsql`

## Instalasi

### 1. Clone Repository

```bash
cd /var/www/html
git clone <repository-url> helpdesk
cd helpdesk
```

### 2. Konfigurasi Database

Buat database PostgreSQL dan import schema:

```bash
sudo -u postgres psql
CREATE DATABASE helpdesk_db;
\c helpdesk_db
\i db/schema.sql
```

Atau import migrasi:

```bash
psql -U postgres -d helpdesk_db -f db/schema.sql
psql -U postgres -d helpdesk_db -f migrations/001_initial_schema.sql
psql -U postgres -d helpdesk_db -f migrations/002_rbac_schema.sql
```

### 3. Konfigurasi Koneksi

Edit file `config.php` sesuai lingkungan Anda:

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'helpdesk_db');
define('DB_USER', 'postgres');
define('DB_PASS', 'your_password');
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
├── index.php               # Dashboard / Daftar tiket (role-based)
├── create_ticket.php       # Form tambah tiket
├── view_ticket.php         # Lihat detail tiket (with permission check)
├── edit_ticket.php         # Edit tiket (admin + teknisi only)
├── delete_ticket.php       # Hapus tiket (admin only)
├── dashboard.php           # Monitoring status tiket (role-specific)
├── user_management.php       # Kelola pengguna (admin only)
├── db/
│   └── schema.sql          # Schema database (users + tickets)
├── migrations/
│   ├── 001_initial_schema.sql
│   └── 002_rbac_schema.sql
├── includes/
│   ├── auth.php            # Auth middleware & permission functions
│   ├── header.php          # Template header (with user info)
│   └── footer.php          # Template footer
└── assets/
    ├── css/style.css       # Styling (includes login page styles)
    └── js/script.js        # JavaScript interactivity
```

## Konfigurasi RBAC

Sistem menggunakan 3 role:

- **Administrator**: Akses penuh ke semua fitur termasuk manajemen pengguna dan penghapusan tiket
- **Teknisi**: Dapat membuat, melihat semua tiket, dan mengedit tiket (termasuk status)
- **Pelapor**: Dapat membuat tiket dan hanya melihat tiket yang dibuat sendiri

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
