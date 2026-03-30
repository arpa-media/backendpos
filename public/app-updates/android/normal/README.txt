TEMPAT FILE UPDATE APK (NORMAL)
=================================

Cara pakai:
1. Upload/copy file APK terbaru ke folder ini.
2. Buat/ubah file manifest.json.
3. Pastikan apk_file di manifest sesuai nama APK.
4. Menu Update Versi di aplikasi Android akan otomatis membaca file manifest ini.

Contoh manifest.json:
{
  "version_name": "1.3.3",
  "version_code": 133,
  "mandatory": false,
  "released_at": "2026-03-30T09:00:00+07:00",
  "apk_file": "JayaPOS-normal-1.3.3.apk",
  "notes": [
    "Perbaikan offline checkout",
    "Stabilisasi sinkronisasi transaksi"
  ]
}

Catatan:
- APK update harus ditandatangani dengan signing key yang sama.
- Existing setting / data lokal Android tetap aman selama package name sama dan update dilakukan di atas aplikasi lama.
