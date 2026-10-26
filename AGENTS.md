<!-- BEGIN:nextjs-agent-rules -->

# This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->

# BloodLink Web — konteks proyek

Klien web (Next.js App Router) untuk BloodLink. Backend Laravel headless ada di
repo terpisah (`bloodlink`) dan tidak boleh dipanggil langsung dari browser.

## Stack

- Next.js 16.4.2 (App Router), React 19.x, TypeScript
- Tailwind CSS + shadcn/ui (token & komponen dasar ditetapkan Kartu 515)
- TanStack Query untuk data fetching (Kartu 517)
- Node.js minimal 20.9.0 — syarat `engines` Next 16, dikunci lewat `.nvmrc` (22)
- npm, bukan pnpm/yarn

## Keputusan arsitektur yang sudah dikunci

Jangan usulkan alternatifnya kecuali diminta eksplisit.

- **Token TIDAK disimpan di `localStorage`.** Disimpan di cookie **httpOnly**
  yang dipasang route handler Next (`src/lib/auth/session.ts`). Browser tidak
  pernah memegang tokennya.
- **Browser tidak memanggil Laravel langsung.** Semua panggilan lewat
  `/bff/*` (`src/app/bff/[...path]/route.ts`), yang meneruskan ke Laravel
  sambil memasang header `Authorization`. `API_BASE_URL` tidak boleh diberi
  awalan `NEXT_PUBLIC_` — itu satu-satunya alasan pola BFF ini berarti.
- **Mobile tidak memakai pola ini** (pakai `expo-secure-store`, panggil
  Laravel langsung). Disengaja — ancamannya berbeda.
- **Tidak ada refresh token.** Backend hanya menerbitkan token Sanctum tanpa
  endpoint refresh. Token mati → hapus cookie → lempar ke halaman login.
- **`Idempotency-Key` dibuat oleh pemanggil** (`newIdempotencyKey()`), sekali
  per aksi pengguna, dipakai ulang di setiap percobaan aksi itu. Jangan
  dibuat di dalam `apiFetch` atau di proxy — itu menghilangkan perlindungan
  retry justru saat dibutuhkan.

## Aturan yang mengikat

- **Klien bercabang pada `error.code`, tidak pernah pada `error.message`.**
  Kontrak dari backend Kartu 50. Pesan boleh berubah kapan saja; kode tidak.
  Sebelas nilai `ErrorCode` di `src/lib/api/types.ts` harus sama persis
  dengan enum `App\Shared\Errors\ErrorCode` di repo backend.
- **Paginasi cursor, bukan nomor halaman.** API tidak mengirim total, jadi UI
  tidak boleh menampilkan "halaman 3 dari 47".
- **Desain mengikuti Kartu 505** — glassmorphism, palet crimson, Sora +
  Manrope. Anggaran blur: `backdrop-filter` maksimal satu lapis per area yang
  di-scroll.
- **Merah hanya untuk tiga hal** — aksi utama, status mendesak, hitung mundur
  kedaluwarsa.
- **Nol string yang di-hardcode.** Semua teks lewat i18n sejak Kartu 519.
- **Setiap daftar tersaring ke fasilitas pemegang token** (Kartu 80) di sisi
  backend. FE tidak menyaring sendiri.

## Alur Git

Sama seperti repo backend (`bloodlink`). `main` dilindungi ruleset: tidak
boleh push langsung, tidak boleh force push, riwayat wajib linear, masuk
hanya lewat pull request yang di-**squash merge**.

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

- **Jangan pernah `git add .`** — sebutkan file satu per satu.
- **Satu kartu = satu commit.**
- Pesan commit harus jujur menggambarkan isi diff-nya.

## Cara kerja dengan papan tugas

Task dilacak di Notion ("BloodLink — Kanban Board"), bukan di repo ini. Jangan
mengubah status kartu di Notion dari sini.
