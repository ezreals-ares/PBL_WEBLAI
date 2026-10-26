# Penyimpanan Gambar (Supabase Storage)

Aplikasi ini berjalan di Vercel, yang filesystem-nya **read-only**. Karena itu
gambar yang diunggah lewat panel admin tidak bisa disimpan ke `assets/img`.
Solusinya: gambar disimpan di Supabase Storage, lalu disajikan lewat URL
`/assets/img/...` yang sama seperti sebelumnya.

## Alur

```
Admin upload
   -> helpers/upload.php        validasi tipe & ukuran
   -> helpers/media.php         POST ke Edge Function
   -> media-upload (Supabase)   tulis ke bucket "media" dengan service_role
   -> bucket media/img/...
```

Saat halaman publik meminta `/assets/img/berita/foto.png`, Vercel mencari file
tersebut di hasil build. Kalau tidak ada, `vercel.json` meneruskan permintaan ke
`https://<ref>.supabase.co/storage/v1/object/public/media/img/...`.

Artinya **tidak ada kode tampilan yang perlu diubah**: gambar lama tetap
dilayani dari build, gambar baru dari Storage.

## Komponen

| Komponen | Lokasi |
|---|---|
| Bucket `media` (public, maks 5MB, JPG/PNG/GIF/WEBP) | Supabase Storage |
| Edge Function `media-upload` | Supabase Edge Functions |
| Helper upload/hapus | `helpers/upload.php`, `helpers/media.php` |
| Rewrite gambar | `vercel.json` |

## Variabel environment

| Nama | Isi |
|---|---|
| `SUPABASE_URL` | `https://<project-ref>.supabase.co` |
| `SUPABASE_STORAGE_BUCKET` | `media` |
| `SUPABASE_UPLOAD_TOKEN` | token bersama untuk Edge Function |

Tanpa `SUPABASE_UPLOAD_TOKEN`, helper otomatis menulis ke `assets/img`
(perilaku lama), sehingga pengembangan lokal tetap berjalan tanpa kredensial.

## Edge Function

`media-upload` menerima `POST ?path=img/<folder>/<file>` dengan header
`x-upload-token`, dan `DELETE ?path=...` untuk menghapus objek. Fungsi ini
memegang `service_role` key di sisi server, jadi aplikasi PHP tidak pernah
menyimpan kunci istimewa tersebut.

Token disimpan sebagai env var Vercel dan di `.env` lokal (keduanya tidak masuk
git). Untuk mengganti token: ubah konstanta di Edge Function, deploy ulang,
lalu perbarui `SUPABASE_UPLOAD_TOKEN` di Vercel dan `.env`.

## Catatan

- Nama file selalu unik (`uniqid()_time()`), jadi tidak ada tabrakan.
- Objek yang dihapus bisa masih tampil sementara dari cache CDN karena
  `cache-control` panjang. Ini tidak berdampak karena nama file tidak dipakai ulang.
