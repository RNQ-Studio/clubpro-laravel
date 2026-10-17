# Distribusi APK (rilis development)

Server menyimpan **satu APK terbaru per app + channel** dan menyajikannya lewat
link yang tidak pernah berubah:

```
https://<domain>/apk/main/dev        # app main, channel dev
https://<domain>/apk/variant/dev
```

Rilis baru menggantikan file lama; file lama langsung dihapus dari storage, jadi
server tidak menumpuk APK. Nama file saat diunduh dibentuk dari metadata rilis:

```
ClubPro-Dev-v1.2.0-build15-2026-10-17-1030WIB.apk
<nama app>-<channel>-v<versionName>-build<versionCode>-<tanggal>-<jam><zona>.apk
```

Nama app diambil dari `config/app_release.php` (`apps`), zona dari `timezone`
(bawaan `Asia/Jakarta`).

## Mengunggah

Pakai skrip rilis di workspace clubpro (`/revisi-rilis`,
`.claude/skills/revisi-rilis/scripts/rilis-apk.sh`). Protokolnya, bila perlu
klien lain:

1. `POST /api/v1/auth/login` → access token akun ber-permission
   `app_releases.create` (role admin/super-admin).
2. `POST /api/v1/app/releases/uploads` (Bearer) dengan JSON:
   `app`, `channel`, `version_name`, `version_code`, `file_size`, `file_sha256`,
   opsional `signing_sha256`, `git_sha`, `release_notes`, `allow_signing_change`.
   Balasan: `upload_id`, `max_chunk_bytes`.
3. `POST /api/v1/app/releases/uploads/{upload_id}/chunks?offset=N` — body mentah
   (`application/octet-stream`), maksimal `max_chunk_bytes`. Chunk ulang pada
   offset yang sama aman (idempoten). Bila nginx membalas 413, kecilkan chunk.
   Offset salah → 409; `GET /api/v1/app/releases/uploads/{upload_id}` memberi
   `received_bytes` untuk melanjutkan.
4. `POST /api/v1/app/releases/uploads/{upload_id}/complete` → server memeriksa
   ukuran, sha256, dan tanda ZIP, memindahkan file ke disk rilis, memperbarui
   baris `app_releases`, lalu menghapus APK sebelumnya.
5. `DELETE /api/v1/app/releases/uploads/{upload_id}` membatalkan sesi. Sesi yang
   ditinggalkan dihapus otomatis setelah `upload_ttl_minutes`.

Chunk dipakai karena batas body nginx/PHP di server (`client_max_body_size`,
`post_max_size`) tidak bisa diandalkan untuk APK puluhan MB.

## Penjaga

- `version_code` wajib lebih besar dari rilis saat ini (409).
- Sertifikat penandatangan (`signing_sha256`) yang berbeda dari rilis
  sebelumnya ditolak (409) kecuali `allow_signing_change: true` — penguji yang
  sudah memasang APK lama tidak akan bisa update di tempat.

## Membaca

- `GET /api/v1/app/releases/latest?app=main&channel=dev` (publik) — metadata,
  `download_filename`, `download_url`.
- Back-office: **App Management → APK Releases** (lihat, salin link, hapus).

## Konfigurasi

| Env | Bawaan | Arti |
|---|---|---|
| `APP_RELEASE_DISK` | `local` | Disk file final (`local` = `storage/app/private`, atau `gcs`) |
| `APP_RELEASE_MAX_SIZE_MB` | `300` | Ukuran APK maksimal |
