# Perlindungan branch `main`

Aturan ini diatur lewat GitHub Settings → Rules → Rulesets. GitHub tidak menyimpan ruleset sebagai file di repo, jadi kalau halaman ini dan pengaturan di GitHub berbeda, **yang berlaku adalah pengaturan di GitHub**. Halaman ini catatan manual.

## Rule yang harus aktif

- Require a pull request before merging
- Require status checks to pass
  - Check wajib: `test` — ini **nama job** di `.github/workflows/ci.yml`, bukan nama workflow (`CI`). Salah pilih membuat semua PR menggantung dengan status "Expected — Waiting for status to be reported".
  - Require branches to be up to date before merging: ya
- Require linear history
- Block force pushes
- Restrict deletions

## Bypass list

Kosong, tidak ada yang boleh menembus, termasuk repository admin. Penjaga yang bisa disuruh minggir bukan penjaga; kalau benar-benar buntu, rule-nya dimatikan lewat Settings — keputusan sadar yang meninggalkan jejak, bukan tombol merge yang diam-diam tetap hijau.

## Alur kerja yang mengikuti aturan ini

```text
branch → PR → CI hijau → squash merge → git switch main && git pull
```

Satu kartu = satu PR = satu commit di `main` setelah squash.

## Kenapa halaman ini ada

PR #2, #3, dan #4 ter-merge ke `main` dalam keadaan CI merah karena required status check belum aktif. CI yang boleh diabaikan sama nilainya dengan tidak punya CI — bahkan lebih buruk, karena memberi rasa aman palsu.