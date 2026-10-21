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
  - Indeks GEO lokasi donor di Redis (`DonorGeoIndex`, kartu 390) adalah **cache**;
    kolom PostGIS `donors.location` tetap otoritatif, dan jalur PostGIS
    (`DonorLocationQuery`) tidak boleh dihapus. Kill-switch
    `matching.redis_geo_enabled` default mati; `donor:reconcile-geo` membangun ulang
    indeks dari PostGIS. Tidak ada data level kantong darah di Redis.
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
  (bukan Sentry), Uptime Kuma, Garage (bukan S3; MinIO community sudah tidak
  dipublikasikan sebagai image), Leaflet + OpenStreetMap
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
- Status unit `released` dan `issued` hanya lewat gerbang, tidak pernah lewat
  `TransitionBloodBatch` (yang menolak keduanya): rilis lewat `ReleaseUnit`
  (panel TTI non-reaktif lengkap), penerbitan lewat `IssueAllocatedUnit`
  (crossmatch TERBARU compatible). Jalur penerbit baru wajib memakai gerbang yang sama.
- Kompensasi (pembatalan/kedaluwarsa permintaan, crossmatch incompatible) adalah
  **satu `DB::transaction`**, bukan saga terdistribusi — tidak ada broker, outbox,
  atau orkestrator. `ReturnAllocatedUnit` adalah satu-satunya jalur kompensasi yang
  mengembalikan unit ke peredaran: alokasi dan reservasinya dilepas bersama, unit
  kembali ke `released` (atau `expired` bila sudah lewat `expires_at`), tidak pernah
  lebih mundur, dan unit `issued` tidak pernah ditarik. Jalur kompensasi baru wajib
  memanggilnya, lalu `RecomputeRequestStatus`.
- Rantai telusur hemovigilans kini lengkap: donor → donasi → unit → alokasi →
  transfusi → reaksi, seluruhnya lewat relasi (`Transfusion::batch()`), bukan kolom
  salinan seperti `donor_id` yang bisa bohong setelah `MergeDonors`. Tabel
  `transfusion_reactions` **sengaja** tidak memuat identitas pasien maupun derajat
  keparahan; jenis reaksi hanya dari master data `transfusion_reaction_types`
  berprovenans `cited`, dan seeder-nya kosong sampai Rujukan Pedoman Medis
  memuat kutipannya.
- Pindaian stok terdekat (`POST /blood-requests/urgent`) mengembalikan **agregat
  per fasilitas saja** — tidak pernah `public_id` kantong, nomor unit, atau
  kedaluwarsa per kantong — dan itu disengaja: `ScopedToFacility` tidak dilubangi;
  detail unit hanya lewat alur transfer (kartu 300). Golongan yang dicari datang
  dari `CheckBloodCompatibility`, tidak pernah ditulis di query.
- `POST /blood-requests/urgent` dijaga limiter bernama `urgent-requests` (di atas limiter
  global `api`): **dua bucket per jam**, per petugas dan per fasilitas, keduanya harus
  lolos, dengan prefix key berbeda. Angkanya operasional di `config/security.php` —
  jangan dinaikkan tiap kali terasa sempit.
- Pemilihan kandidat donor (`SelectDonorCandidates`) diurutkan **murni menurut
  jarak**, tanpa skor atau pembobotan, dan kandidat **tidak pernah** membawa data
  kontak. Modul Request membaca modul Donor hanya lewat port `App\Shared\Matching\DonorDirectory`
  (implementasi `EloquentDonorDirectory` di modul Donor, diikat di `AppServiceProvider`)
  — bukan dengan menambah izin deptrac.
- `BloodRequestController::candidates()` **sengaja** dua round-trip (`COUNT` lalu
  `candidatesFor()`): `COUNT`-nya Index Only Scan atas `(blood_request_id, released_at)`,
  murah. Jangan digabung (kartu 445). Anggaran query dan `EXPLAIN` di test memakai
  `Tests\Concerns\InspectsQueries`; index baru hanya dengan rencana sebelum/sesudah yang berbeda.
- Object storage: berkas lampiran masuk disk `s3` (Garage self-hosted, lihat
  service `garage` di docker-compose), bukan disk `local` dan bukan ke dalam
  working tree. Disk itu `visibility => private` dan `throw => true`; jangan
  dilonggarkan. Bucket tidak pernah dibuat publik — berkas keluar hanya lewat
  URL bertanda tangan yang diterbitkan aplikasi.

## Pencarian spasial

PostGIS (`geography` + GiST) adalah sumber kebenaran **otoritatif** untuk lokasi
donor dan fasilitas. Kampanye panggilan donor berjenjang 5/15/30 km (kartu 400)
dan pemilihan kandidatnya (kartu 410) memakai PostGIS **saja**: indeksnya sudah
ada, dan kelayakan donor dihitung di database yang sama. Indeks GEO Redis (kartu
390) hanya cache opsional di balik kill-switch yang default mati, dan **tidak
dipakai** kampanye — Redis yang di-flush atau basi tidak boleh bisa menghentikan
atau membelokkan panggilan untuk permintaan darurat. Kampanye dimulai oleh
manusia; penjadwal (`matching:advance-campaigns`) hanya memajukan dan menutup.

## Kanal notifikasi

Sistem ini hanya mengirim lewat email (SMTP) dan FCM push. SMS dan WhatsApp
Business API dibayar per pesan, sementara kampanye panggilan donor mengirim ke
banyak penerima sekaligus — biaya tanpa batas atas pada proyek tanpa pemasukan.
Pembatasannya dijaga `ChannelPolicy`, bersumber dari `config/notification.php`.
Menambah kanal berarti menambah case enum **dan** implementasi pengirimnya di hari
yang sama; menambah baris di config saja tidak cukup dan memang disengaja begitu
(`ChannelPolicy` gagal dibangun untuk nilai config tanpa case enum, dan
`ChannelPolicyTest` merah untuk case enum tanpa pengirim).

## Jalur kirim notifikasi dan Horizon

- Pintu masuknya `DispatchNotification`: satu baris `notification_deliveries`
  berstatus `pending` per alamat per kanal, ditulis **sebelum** mengirim, lalu satu
  job `SendNotification` per baris di antrean `notifications`. Satu job per
  penerima per kanal, tidak pernah satu job per kampanye.
- Pengirim **hanya** diresolusi lewat `ResolveSender`, yang memeriksa `ChannelPolicy`
  lebih dulu. Tidak ada kode yang meminta `MailNotificationSender` atau
  `FcmNotificationSender` langsung.
- Payload job **hanya** `public_id` delivery — tidak pernah model, alamat, atau
  variabel template (semua itu berakhir di Redis dan `failed_jobs.payload`).
  `SendNotification` menyetel `afterCommit()` di constructor-nya sendiri karena
  `after_commit => false` di `config/queue.php`.
- Isi pesan yang sudah dirender dan alamat penerima tidak pernah disimpan. Variabel
  template disimpan terenkripsi (`encrypted:array`) dan dikosongkan saat `sent`.
- Queue berjalan di Redis dan dikelola **Horizon saja** (service `worker` menjalankan
  `php artisan horizon`). Jangan jalankan `queue:work` di samping Horizon. Dashboard
  `/horizon` hanya terbuka di `APP_ENV=local`; di lingkungan lain 403 untuk siapa pun.
- FCM HTTP v1: access token OAuth2 ditukar manual dari service account (JWT RS256),
  bukan server key. Berkas service account tidak pernah di repo; path-nya dari
  `FCM_CREDENTIALS_PATH`.

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
