# BloodLink — konteks proyek

Sistem manajemen bank darah. Backend **headless** (JSON saja). Klien web (Next.js)
dan mobile ada di repo terpisah — bukan di sini.

Setiap kesalahan data di sini punya konsekuensi medis: unit yang belum lolos uji
lab tidak boleh keluar, golongan darah tidak kompatibel tidak boleh dialokasikan,
donor yang belum cukup jarak waktunya tidak boleh mendonor. Kalau sebuah aturan
domain terasa berlebihan untuk aplikasi biasa, itu memang disengaja.

## Stack

- Laravel 13, PHP 8.5
- PostgreSQL 17 + PostGIS (image `postgis/postgis`, bukan `postgres` polos)
- Redis — cache, session, dan queue
- Docker Compose untuk dev; `app` dan `worker` adalah service terpisah

## Keputusan arsitektur yang sudah dikunci

Jangan usulkan alternatifnya kecuali diminta eksplisit.

- **Modular monolith**, bukan microservices. Modul ada di
  `app/Modules/{Identity,Donor,Inventory,Request,Notification}`.
- **PostgreSQL + PostGIS**, bukan MySQL — fitur inti butuh pencarian donor
  dalam radius 5/15/30 km lewat `geography` + indeks GiST.
- **Bearer token (Sanctum), stateless** — bukan cookie session. Konsekuensinya
  `supports_credentials => false` di `config/cors.php`, dan
  `sanctum/csrf-cookie` tidak boleh ada di `paths`.
- **Semua tool wajib gratis / open source.** Padanan yang dipakai: GlitchTip
  (bukan Sentry), Uptime Kuma, MinIO (bukan S3), Leaflet + OpenStreetMap
  (bukan Google Maps), Nominatim untuk geocoding. Kalau sebuah langkah butuh
  layanan berbayar, cari padanan gratisnya dulu dan sebutkan.

## Aturan keras

- **Headless.** Tidak ada Blade, Vite, Node, atau npm di repo ini.
  `routes/web.php` kosong permanen. Jangan menambah apa pun ke sana.
- **Batas modul dijaga `deptrac`.** Antar-modul hanya boleh lewat
  `App\Modules\<Modul>\Domain\Events\*`. Mengimpor entity, repository, atau
  value object milik modul lain adalah pelanggaran, meski kodenya jalan.
- **`env()` hanya boleh dipanggil di dalam `config/*.php`.** Di luar itu ia
  mengembalikan `null` setelah `config:cache` jalan di production — diam-diam,
  tanpa error. `grep -rn "env(" app/ routes/` harus selalu kosong.
- **Tidak ada kredensial di file yang ter-commit.** Termasuk
  `docker-compose.yml` dan `.env.example`. Nilai dibaca dari env; yang wajib
  ada pakai `${VAR:?pesan}` supaya gagal cepat dengan pesan jelas.
- **Laravel 11+**: tidak ada `app/Http/Kernel.php`. Routing dan middleware
  dikonfigurasi di `bootstrap/app.php`. Abaikan tutorial yang menyuruh
  mengedit `Kernel.php`.
- Kolom uang dan kuantitas tidak boleh `float`. Index wajib di kolom foreign
  key dan kolom yang sering di-query.
- Queue job tidak mewarisi konteks fasilitas dari request. Job yang perlu
  memeriksa izin harus memanggil `setPermissionsTeamId()` sendiri dari data
  di payload-nya.

## Perintah yang sering dipakai

```bash
docker compose up -d
docker compose down -v          # buang volume; wajib kalau DB_PASSWORD diganti
php artisan migrate

./vendor/bin/pint --test        # format
./vendor/bin/deptrac analyse --fail-on-uncovered   # batas modul
./vendor/bin/phpstan analyse    # analisis statis
php artisan test
```

Lima pemeriksaan itu sama persis dengan yang dijalankan CI. Jalankan di lokal
sebelum push — jangan pakai runner GitHub sebagai tempat mencoba-coba.

## Alur Git

`main` dilindungi ruleset: tidak boleh push langsung, tidak boleh force push,
riwayat wajib linear, masuk hanya lewat pull request yang di-**squash merge**.

```bash
git switch main && git pull
git switch -c <tipe>/<slug-kartu>
git status                      # DIPERIKSA sebelum git add
git add <daftar file eksplisit>
git commit -m "<judul menyerupai judul kartu>"
git push -u origin <nama-branch>
gh pr create --fill
# tunggu CI hijau
gh pr merge --squash --delete-branch
git switch main && git pull
```

- **Jangan pernah `git add .`** — itu cara `.env`, `vendor/`, dan file cache
  ikut ter-commit. Sebutkan file satu per satu.
- **Satu kartu = satu commit.** Judul commit dibuat menyerupai judul kartu,
  karena itulah yang dipakai saat review untuk mencocokkan commit dengan task.
- Pesan commit harus jujur menggambarkan isi diff-nya. Pesan yang mengklaim
  lebih dari yang dikerjakan lebih berbahaya daripada bug-nya.

## Cara kerja dengan papan tugas

Task dilacak di Notion (papan "BloodLink — Kanban Board"), bukan di repo ini.
Kalau sebuah kartu berstatus **Revision**, isi komentar revisinya di-paste ke
prompt — di situ sudah ada nama file, nomor baris, alasan, dan potongan kode
yang benar. Jangan menebak-nebak isi kartunya.

Jangan mengubah status kartu di Notion dari sini. Pergeseran status dilakukan
manual oleh pemilik repo dan oleh reviewer.

## Verifikasi negatif — wajib

Setiap penjaga otomatis (aturan deptrac, step CI, validasi, constraint DB)
harus dibuktikan **menggigit** sebelum dipercaya: sengaja langgar sekali,
pastikan ia merah, lalu kembalikan dan pastikan hijau.

Hijau yang belum pernah merah belum membuktikan apa pun.
