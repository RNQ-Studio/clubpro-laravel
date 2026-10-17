# Laravel Starter — API Backend & Back-office

Starter project berbasis **Laravel + PostgreSQL** yang dirancang sebagai fondasi untuk dua kebutuhan sekaligus:

1. **API backend** untuk aplikasi mobile **Flutter** (token-based auth via OAuth2).
2. **Back-office web UI** untuk manajemen internal — user management, role & permission, dan data master (session-based auth via Filament).

Tujuannya adalah menyediakan kerangka kerja yang bersih, konsisten, dan siap dikembangkan, tanpa over-engineering, sehingga tim bisa langsung fokus membangun fitur bisnis.

---

## Stack Teknologi

| Komponen | Pilihan | Versi yang Direkomendasikan |
|---|---|---|
| Framework | Laravel | `13.x` (terpasang `13.11`) |
| Bahasa | PHP | `8.3+` (8.4 didukung) |
| Database | PostgreSQL | `16` atau `17` |
| API Auth | Laravel Passport | `13.x` (OAuth2, Password Grant) |
| Back-office UI | Filament | `5.x` |
| RBAC | spatie/laravel-permission | `7.x` |
| Cloud Storage | Google Cloud Storage (GCS) | Disk `gcs` terintegrasi |
| Cache / Queue (opsional) | Redis | `7.x` |
| Aset / Frontend | Node.js | `20.19+` atau `22.12+` (LTS direkomendasikan) |
| PHP runtime lokal | Laravel Herd / Sail / Valet | sesuai OS |

> ⚠️ **Catatan versi:** Semua versi di atas adalah rekomendasi pada saat dokumen dibuat. Di awal Sesi 1, jalankan `composer create-project laravel/laravel` dan cek rilis stable terbaru dari tiap package sebelum mengunci versi di `composer.json`.

---

## Prinsip Desain

- **API-first** — Kontrak API adalah warga kelas satu. Back-office dan mobile adalah dua konsumen yang setara di atas domain logic yang sama.
- **Separation of concerns** — Controller tipis, logika bisnis di **Service layer**, akses data via **Eloquent** (tanpa Repository pattern — lihat [architecture.md](docs/architecture.md)).
- **Konsistensi kontrak** — Semua response API mengikuti satu format JSON standar (envelope + error format konsisten).
- **Single source of truth untuk authorization** — Satu sistem RBAC (spatie) dipakai bersama oleh API guard dan web guard.
- **Hindari over-engineering** — Tidak ada abstraksi spekulatif. Tambahkan layer hanya ketika kebutuhan nyata muncul.
- **Convention over configuration** — Ikuti konvensi Laravel; jangan melawan framework.

---

## Daftar Isi Dokumentasi

| Dokumen | Isi |
|---|---|
| [docs/features.md](docs/features.md) | Katalog dan spesifikasi seluruh fitur sistem terimplementasi secara komprehensif |
| [docs/architecture.md](docs/architecture.md) | Arsitektur sistem, layering, struktur direktori, strategi auth, dan praktik integrasi Flutter ↔ Laravel |
| [docs/data_master_pattern.md](docs/data_master_pattern.md) | Pola standar dan petunjuk replikasi CRUD data master baru berdasarkan contoh `Category` |
| [docs/deployment.md](docs/deployment.md) | Panduan go-live produksi lengkap (Nginx block, Supervisor worker, dan migrasi) |
| [CLAUDE.md](CLAUDE.md) | Panduan perintah cepat (linting, test, static analysis) untuk AI agent dan kolaborator |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Aturan kontribusi repositori, manajemen Git Flow, konvensi Conventional Commits, dan quality gate |
| [SECURITY.md](SECURITY.md) | Kebijakan pelaporan privat celah keamanan sensitif beserta SLA respons |

---

## Panduan Memulai Cepat (Onboarding Guide)

Jika Anda baru saja melakukan *clone* pada project ini, ikuti langkah-langkah di bawah ini untuk menyalakan lingkungan pengembangan lokal Anda kurang dari 5 menit.

---

### Opsi A: Instalasi Menggunakan Tooling Lokal (Composer)

> **Prasyarat**: Anda wajib memiliki **PHP 8.3+**, **Composer 2**, **PostgreSQL 14+**, dan **Node 20.19+ atau 22.12+ (LTS direkomendasikan)** terpasang di sistem operasi lokal Anda.
> *Catatan Windows*: Jika PATH default Anda masih menggunakan PHP lama, Anda dapat mengarahkan path php secara manual atau menggunakan binary PHP 8.3 spesifik (mis. `C:\php8.3.6\php.exe`).

```bash
# Langkah 1: Jalankan setup otomatis dependensi & aset
# (Bagi pengguna Windows/non-make, gunakan ini. Untuk Linux/macOS dengan make: make setup)
composer run setup

# Langkah 2: Buat database di PostgreSQL lokal Anda
# (Isi kredensial PostgreSQL di berkas .env Anda terlebih dahulu)
# DB_CONNECTION=pgsql
# DB_DATABASE=laravel_starter
# DB_USERNAME=<user>
# DB_PASSWORD=<password>

# Langkah 3: Jalankan migrasi, seeder default, kunci Passport, & inisialisasi awal wajib
php artisan migrate:fresh --seed
php artisan passport:keys --force
php artisan storage:link

# Buat Passport Password Client (Salin Client ID & Client Secret ke file .env Anda!)
php artisan passport:client --password

# Unduh dan jalankan seeder wilayah administratif Indonesia offline
php artisan regions:download
php artisan db:seed --class=RegionSeeder

# Langkah 4: Jalankan server dev lokal (Concurrently: serve, queue, logs, vite)
# (Bagi pengguna Windows/non-make, gunakan ini. Untuk Linux/macOS dengan make: make dev)
composer run dev

Project siap diakses di [http://127.0.0.1:8000](http://127.0.0.1:8000)
```

---

### Opsi B: Instalasi Menggunakan Docker (Laravel Sail)

Bagi Anda yang ingin menginisialisasi lingkungan pengembangan instan tanpa perlu memasang PHP, PostgreSQL, Node, atau Redis secara lokal di mesin Anda, Anda dapat menggunakan kontainerisasi **Laravel Sail** yang sudah terintegrasi.

> **Prasyarat**: Aplikasi **Docker Desktop** telah terpasang dan berjalan di sistem Anda.

```bash
# Langkah 1: Persiapan Environment
cp .env.example .env

# Langkah 2: Nyalakan kontainer Docker secara latar belakang (background)
# (Proses build awal image akan memakan waktu beberapa menit)
./vendor/bin/sail up -d

# Langkah 3: Unduh dependensi composer di dalam kontainer
./vendor/bin/sail composer install

# Langkah 4: Generate key aplikasi & kunci Passport
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan passport:keys

# Langkah 5: Jalankan migrasi database & seeders
./vendor/bin/sail artisan migrate:fresh --seed

# Langkah 6: Jalankan inisialisasi awal wajib (Passport client, symlink, & wilayah offline)
./vendor/bin/sail artisan storage:link
./vendor/bin/sail artisan passport:client --password
./vendor/bin/sail artisan regions:download
./vendor/bin/sail artisan db:seed --class=RegionSeeder

# Langkah 7: Build aset frontend
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

Setelah kontainer berjalan, untuk menghentikan kontainer Anda dapat menjalankan `./vendor/bin/sail down`.

---

## 🌐 Cara Mengakses Aplikasi

### Produksi
Backend ClubPro hanya satu: **[https://anaklangit.manahpro.web.id](https://anaklangit.manahpro.web.id)** — panel admin di `/admin`, health check di `/api/v1/health`, base URL API app mobile `https://anaklangit.manahpro.web.id/api/`. Alamat `localhost` di bawah hanya untuk server dev lokal.

Setelah seluruh proses inisialisasi selesai dan server dev berjalan, Anda dapat mengakses layanan di alamat berikut:

### A. Jika Menggunakan Opsi A (Tooling Lokal / PHP Artisan)
* **Aplikasi Utama (Web / Landing Page)**: [http://localhost:8000](http://localhost:8000) (atau [http://127.0.0.1:8000](http://127.0.0.1:8000))
* **Panel Administrasi (Filament v5)**: [http://localhost:8000/admin](http://localhost:8000/admin)
* **Dokumentasi API Interaktif (Scramble)**: [http://localhost:8000/docs/api](http://localhost:8000/docs/api)
* **Kesehatan API (Health Check JSON)**: [http://localhost:8000/api/v1/health](http://localhost:8000/api/v1/health)

### B. Jika Menggunakan Opsi B (Docker / Laravel Sail)
* **Aplikasi Utama (Web / Landing Page)**: [http://localhost](http://localhost) (Port 80 default)
* **Panel Administrasi (Filament v5)**: [http://localhost/admin](http://localhost/admin)
* **Dokumentasi API Interaktif (Scramble)**: [http://localhost/docs/api](http://localhost/docs/api)
* **Kesehatan API (Health Check JSON)**: [http://localhost/api/v1/health](http://localhost/api/v1/health)
* **Penguji Email (Mailpit Dashboard)**: [http://localhost:8025](http://localhost:8025)

> 🔑 **Kredensial Default Login**: Gunakan email `admin@example.com` dan password `password` untuk masuk ke Panel Admin atau menguji rute login API. (Lihat rinciannya di [Akun Default untuk Login Pengembang](#-akun-default-untuk-login-pengembang)).

---

### ⚠️ Langkah Wajib Pasca Inisialisasi (Passport Client Setup)

Endpoint login API (`POST /api/v1/auth/login`) bergantung sepenuhnya pada Laravel Passport Password Client. Pada instalasi/clone baru, Anda **WAJIB** membuat Password Client baru dengan perintah:

```bash
# Untuk Setup Lokal:
php artisan passport:client --password

# Untuk Setup Docker Sail:
./vendor/bin/sail artisan passport:client --password
```

Setelah menjalankan perintah tersebut, Anda akan mendapatkan output **Client ID** dan **Client Secret**. Salin kedua nilai tersebut dan masukkan ke dalam variabel lingkungan berikut di file `.env`:
```env
PASSPORT_PASSWORD_CLIENT_ID=<Client-ID-Anda>
PASSPORT_PASSWORD_CLIENT_SECRET=<Client-Secret-Anda>
```
> [!WARNING]
> Jika langkah Passport Client ini terlewatkan, seluruh endpoint Login API akan mengembalikan error kegagalan autentikasi atau *internal server error*.

---

### 🛠️ Pengaturan Tambahan Wajib (Firebase FCM, Storage Symlink, & Queue Worker)

Agar seluruh fitur premium seperti pengunggahan avatar dan pengiriman push notification FCM asinkron berjalan lancar tanpa error di lingkungan lokal, Anda **WAJIB** melakukan konfigurasi berikut:

#### 1. Kredensial Firebase Cloud Messaging (FCM)
Aplikasi menggunakan **Firebase Admin SDK** untuk mengirim notifikasi ke Flutter client.
1. Unduh berkas **Service Account JSON** dari [Firebase Console](https://console.firebase.google.com/) Anda (Project Settings → Service Accounts → Generate New Private Key).
2. Simpan berkas JSON tersebut di direktori lokal Anda, misalnya di `storage/app/firebase/service-account.json`.
3. Buka berkas `.env` lokal Anda, lalu daftarkan jalurnya pada variabel berikut:
   ```env
   FIREBASE_CREDENTIALS=storage/app/firebase/service-account.json
   ```
> [!IMPORTANT]
> Jangan pernah mengunggah berkas `service-account.json` asli Anda ke Git repository karena berkas tersebut bersifat rahasia (sudah di-*ignore* otomatis di `.gitignore`).

#### 2. Aktivasi Storage Symlink (Akses URL Avatar)
Fitur unggah avatar pengguna menyimpan berkas di storage terlindungi. Jalankan symlink agar gambar dapat diakses oleh browser/Flutter:
```bash
# Untuk Setup Lokal:
php artisan storage:link

# Untuk Setup Docker Sail:
./vendor/bin/sail artisan storage:link
```
> [!WARNING]
> Jika langkah ini terlewat, pengunggahan avatar akan sukses, namun URL avatar yang dikembalikan API akan menghasilkan error **404 Not Found** saat dibuka.

#### 3. Menjalankan Queue Worker (Pemproses Antrean Latar Belakang)
Pengiriman notifikasi Firebase diproses secara asinkron agar performa API sangat cepat. Untuk memproses antrean di lokal, jalankan perintah worker berikut di terminal terpisah:
```bash
# Opsi 1: Menggunakan Perintah Concurrently (Direkomendasikan - Otomatis Queue + Serve + Vite)
make dev

# Opsi 2: Menjalankan Queue Worker Lokal secara Manual
php artisan queue:listen

# Opsi 3: Menjalankan Queue Worker di Docker Sail secara Manual
./vendor/bin/sail artisan queue:listen
```

#### 4. Kredensial & Driver Google Cloud Storage (GCS)
Aset diunggah secara terdistribusi langsung ke GCS menggunakan disk `gcs`.
1. Unduh berkas **Service Account JSON** yang memiliki akses read/write ke Google Cloud Storage bucket Anda.
2. Simpan di direktori lokal Anda, misalnya di `storage/app/gcs/service-account.json`.
3. Tambahkan konfigurasi berikut ke file `.env` Anda:
   ```env
   GOOGLE_CLOUD_PROJECT_ID=nama-project-gcp-anda
   GOOGLE_CLOUD_KEY_FILE=storage/app/gcs/service-account.json
   GOOGLE_CLOUD_STORAGE_BUCKET=nama-bucket-gcs-anda
   ```

---

### 🌐 Database Wilayah Geografis Global (Opsional & Offline)

Starter ini dilengkapi database wilayah geografis global mencakup seluruh negara di dunia (249.036 data parent-child). Agar proses inisialisasi lokal Anda lancar dan tidak terhambat request HTTP eksternal yang lambat, seeding dilakukan secara **offline** menggunakan JSON fixtures lokal di storage.

#### Langkah 1: Unduh Berkas Wilayah secara Offline
Jalankan perintah berikut untuk mengunduh berkas wilayah ke storage Anda:
```bash
# Untuk Setup Lokal:
php artisan regions:download

# Untuk Setup Docker Sail:
./vendor/bin/sail artisan regions:download
```

#### Langkah 2: Masukkan Data ke Database (Seeding)
Pilihlah salah satu dari dua cara berikut untuk memasukkan data wilayah ke database:

* **Opsi A: Jalankan Seeder Secara Langsung (Direkomendasikan)**
  Jika Anda ingin langsung memasukkan data wilayah tanpa menghapus data tabel lainnya yang sudah ada:
  ```bash
  # Untuk Setup Lokal:
  php artisan db:seed --class=RegionSeeder

  # Untuk Setup Docker Sail:
  ./vendor/bin/sail artisan db:seed --class=RegionSeeder
  ```

* **Opsi B: Aktifkan Pengisian Otomatis Saat Reset Database**
  Jika Anda ingin data wilayah terisi otomatis setiap kali menjalankan `php artisan migrate:fresh --seed`, atur variabel berikut di berkas `.env` Anda:
  ```env
  SEED_REGIONS=true
  ```

---

### 🔑 Akun Default untuk Login Pengembang

Setelah database berhasil diinisialisasi, Anda dapat menggunakan kredensial bawaan (*seeder*) berikut untuk masuk ke sistem:

| Username / Email | Password | Role Akses (Spatie) | Tujuan Login |
|---|---|---|---|
| `admin@example.com` | `password` | `super-admin` | Panel Admin `/admin` & Login API |

> [!NOTE]
> Peran default `super-admin` memiliki bypass otorisasi penuh melalui `Gate::before`. Anda dapat menambahkan pengguna baru atau menetapkan peran `admin` atau `staff` lainnya secara visual langsung melalui Panel Admin Filament di menu **Users**.

---

### 🗺️ Peta Rute & Endpoint Utama API

Untuk mempercepat integrasi dengan **Flutter Client**, berikut adalah ringkasan endpoint API utama yang terstruktur di bawah prefix `/api/v1/`:

* **Autentikasi Mandiri & Pengelolaan Sesi**
  * `POST /api/v1/auth/register` — Pendaftaran mandiri pengguna baru.
  * `POST /api/v1/auth/login` — Masuk sistem menggunakan email & password (mengembalikan access & refresh token).
  * `POST /api/v1/auth/refresh` — Memperbarui sesi menggunakan refresh token.
  * `POST /api/v1/auth/logout` — Keluar sistem (mencabut token aktif).
  * `POST /api/v1/auth/logout-all` — Keluar dari seluruh perangkat secara massal.
* **OTP & Verifikasi Telepon / Email**
  * `POST /api/v1/auth/otp/send` — Mengirimkan kode OTP login/verifikasi.
  * `POST /api/v1/auth/otp/verify` — Memverifikasi kode OTP.
  * `POST /api/v1/auth/email/send-verification` — Mengirim ulang tautan verifikasi email.
  * `POST /api/v1/auth/email/verify` — Memproses verifikasi email.
  * `POST /api/v1/auth/forgot-password` — Mengirimkan tautan pemulihan kata sandi.
  * `POST /api/v1/auth/reset-password` — Melakukan reset kata sandi baru.
* **Pengelolaan Profil (Wajib Autentikasi `Bearer Token`)**
  * `GET /api/v1/auth/me` — Mengambil data profil aktif.
  * `PUT /api/v1/auth/me` — Memperbarui informasi profil pribadi.
  * `POST /api/v1/auth/avatar` — Mengunggah foto profil (*avatar*) pengguna secara aman.
  * `POST /api/v1/auth/change-password` — Mengubah password pribadi.
* **Notifikasi Pengguna (Wajib Autentikasi)**
  * `GET /api/v1/notifications` — List notifikasi in-app pengguna.
  * `GET /api/v1/notifications/unread-count` — Menghitung jumlah notifikasi yang belum dibaca.
  * `POST /api/v1/notifications/read-all` — Menandai seluruh notifikasi telah dibaca.
  * `POST /api/v1/notifications/{notification}/read` — Menandai satu notifikasi tertentu telah dibaca.
* **Manajemen Aset & Berkas (Wajib Autentikasi)**
  * `POST /api/v1/assets/upload` — Mengunggah berkas secara atomik ke Google Cloud Storage (GCS) lengkap dengan metadata & hash checksum.
* **Data Master Kategori (Terproteksi Kebijakan RBAC)**
  * `GET /api/v1/categories` — List data master kategori (Mendukung whitelist filter & sort).
  * `POST /api/v1/categories` — Membuat kategori baru.
  * `GET /api/v1/categories/{category}` — Detail satu kategori.
  * `PUT /api/v1/categories/{category}` — Memperbarui kategori.
  * `DELETE /api/v1/categories/{category}` — Menghapus kategori (*soft deletes*).

---

### 🧪 Verifikasi Koneksi & Menjalankan Tes

* **Verifikasi API**: Buka `GET /api/v1/health` → harus mengembalikan envelope JSON sukses `{ "success": true, "message": "OK", ... }`.
* **Dokumentasi API**: Scramble menyediakan dokumentasi interaktif di `/docs/api` dan OpenAPI JSON di `/docs/api.json` saat berada di environment `local`.
* **Testing Database**: Pengujian secara default berjalan di database PostgreSQL terpisah bernama `laravel_starter_test`.
  * *Setup Lokal*: Buat database test terlebih dahulu di pgsql dengan `createdb laravel_starter_test`.
  * *Setup Docker Sail*: Database test sudah terbuat secara otomatis.
* **Menjalankan Tes & Linting**:
  ```bash
  # Menjalankan seluruh test suite
  composer test            # alternatif: make test

  # Menjalankan formater koding otomatis (Pint)
  composer lint            # alternatif: make lint

  # Menjalankan static analysis (PHPStan/Larastan)
  composer analyse         # alternatif: make analyse

  # Menjalankan quality gate penuh sebelum melakukan git push
  make quality             # alternatif: composer lint && composer analyse && composer test
  ```

> [!TIP]
> **Fallback ke SQLite In-Memory:**
> Jika Anda belum memasang PostgreSQL test atau ingin menjalankan tes secara super cepat menggunakan driver SQLite `:memory:`, Anda cukup menghapus komentar (uncomment) bagian SQLite di berkas `phpunit.xml` atau mengatur `DB_CONNECTION=sqlite` dan `DB_DATABASE=:memory:` di berkas `.env.testing`.

---

## Panduan Pra-Push & Quality Gates (Pre-Push Best Practices)

Untuk menjaga repositori dan pipeline CI GitHub tetap bersih, hijau (lulus), dan bebas dari kegagalan build, setiap pengembang **sangat direkomendasikan** untuk memvalidasi kodenya secara lokal sebelum menjalankan `git push`.

Jalankan rangkaian perintah berikut secara berurutan di terminal lokal Anda sebelum melakukan push:

### 1. Jalankan Linter & Pemformat Kode (Pint)
Merapikan dan memformat kode Anda secara otomatis sesuai standar PSR-12:
```bash
# Melakukan perbaikan otomatis (direkomendasikan)
vendor/bin/pint

# Hanya memeriksa tanpa mengubah (opsional, untuk memastikan)
vendor/bin/pint --test
```

### 2. Jalankan Analisis Kode Statis (PHPStan/Larastan)
Mendeteksi potensi kesalahan pengetikan, parameter salah, atau bug tipe data di level strictness tinggi (Level 5):
```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

### 3. Jalankan Seluruh Test Suite (PHPUnit)
Memastikan tidak ada fitur yang rusak (*zero-regression*) akibat perubahan kode baru Anda:
```bash
# Menggunakan test runner artisan
php artisan test

# ATAU menjalankan PHPUnit secara langsung
vendor/bin/phpunit
```

> [!IMPORTANT]
> **Checklist Utama Sebelum Push:**
> 1. **Pint Pass:** `vendor/bin/pint --test` menghasilkan `Pint passed`.
> 2. **PHPStan Pass:** Analisis statis mengembalikan `[OK] No errors`.
> 3. **Tests Pass:** Seluruh pengujian (Feature & Unit) berwarna hijau (`OK / 100% passed`).
> 4. **Clear Configuration Cache:** Pastikan Anda telah menjalankan `php artisan config:clear` agar variabel lingkungan pengujian di `.env.testing` atau `phpunit.xml` dibaca secara dinamis dan tidak macet di cache konfigurasi.

> [!TIP]
> **Catatan Keamanan Produksi:** Untuk lingkungan production, sangat direkomendasikan menggunakan pengguna PostgreSQL khusus dengan hak akses terbatas (*least-privilege*), bukan akun administrator database default `postgres`.
