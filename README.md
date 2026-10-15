# ESBAS Retail: POS Multi-Cabang, BOM, ESS & Akuntansi Laba Rugi

Sistem Retail Terpadu: Point of Sale (POS), Manajemen Karyawan, Master Produk & Varian, Resep Bahan Baku / Bill of Materials (BOM), Kartu Pergerakan Stok (Stock Movement Ledger), Employee Self Service (ESS), dan Laporan Laba Rugi Real-Time.

---

## 🚀 Cara Menjalankan Aplikasi

Aplikasi dibangun menggunakan **PHP & SQLite** bawaan (tanpa dependensi eksternal / node_modules) dan dapat langsung dijalankan:

1. Buka terminal (PowerShell / Command Prompt) di direktori ini:
   ```powershell
   cd C:\Users\lenovo\.gemini\antigravity\scratch\esbas-pos
   ```
2. Jalankan PHP Built-in Server:
   ```powershell
   php -S localhost:8000
   ```
3. Buka peramban Anda di:
   👉 **http://localhost:8000**

*(Atau letakkan folder ini di `C:\xampp\htdocs\esbas-pos` dan buka via `http://localhost/esbas-pos`)*.

---

## 🔑 Akun Login Pengujian (Demo Accounts)

Sistem mewajibkan login sebelum mengakses fitur. Disediakan tombol **1-Click Login Cepat** di halaman muka atau gunakan kredensial berikut:

| Peran (Role) | NIK / Email | Password / PIN | Nama Karyawan | Hak Akses Utama |
| :--- | :--- | :--- | :--- | :--- |
| **Super Admin** | `ADM001` / `admin@retail.com` | `admin123` / `999999` | Budi Santoso | Akses Penuh: Semua Cabang, CRUD Karyawan, Master Produk, Laba Rugi Konsolidasi |
| **Branch Manager** | `MGR001` / `hendra@jkt.retail.com`| `manager123` / `888888`| Hendra Wijaya | Kelola Cabang Jakarta, Approval Shift Swap, Verifikasi Reimbursement, Laba Rugi Cabang |
| **Kasir (Cashier)**| `CSR001` / `siti@jkt.retail.com` | `kasir123` / `123456` | Siti Rahmawati | POS Penjualan, Buka/Tutup Shift, Presensi GPS, Pantau Komisi 1.5% |
| **Staf Gudang** | `STF001` / `rian@jkt.retail.com` | `staff123` / `000000` | Rian Kurniawan | Manajemen Stok, Mutasi Masuk/Keluar, Pengaturan Resep Bahan Baku |

---

## 📋 8 Modul Sistem Lengkap Sesuai Ketentuan

### 1. Form Login & Kontrol Sesi
- Layar login muncul pertama kali saat masuk aplikasi.
- Autentikasi aman berbasis sesi, validasi NIK/Email dan Password/PIN 6 digit.
- Tombol *Logout* di bilah atas untuk keluar sesi kerja dengan aman.

### 2. Manajemen Karyawan (CRUD)
- **Create**: Tambah karyawan baru dengan NIK unik, Nama, Peran (Super Admin, Branch Manager, Kasir, Staff), Cabang Penugasan, Email, No HP, PIN Kasir, dan Persentase Komisi Penjualan.
- **Read**: Tabel karyawan lengkap dengan status keaktifan dan pencarian.
- **Update**: Edit data karyawan, promosi/mutasi cabang, dan penyesuaian komisi.
- **Delete**: Fitur penonaktifan akun karyawan (*Soft Delete*).

### 3. Manajemen Produk, Kategori, Varian, dan Jenis Produk
- **3 Jenis Produk (Product Types)**:
  1. **Barang Jadi (Finished Goods)**: Produk jadi siap jual di kasir.
  2. **Bahan Baku (Raw Material / Ingredient)**: Bahan penyusun dalam resep BOM (Biji Kopi, Susu UHT, Sirup, Cup, Daging, Keju, Roti).
  3. **Jasa (Service)**: Layanan non-stok fisik.
- **Kategori Produk**: Minuman, Bakery, Makanan, Bahan Baku, Merchandise.
- **Varian Produk**: Mendukung varian turunan (Regular 12oz, Large 16oz) dengan penyesuaian harga jual tambahan.
- **CRUD Produk**: SKU, Barcode EAN-13, Nama, Satuan, HPP, Harga Jual, Reorder Point, dan Icon Emoji.

### 4. Formula Resep atau Bill of Materials (BOM)
- Definisikan formula resep bahan baku untuk setiap Barang Jadi:
  - Contoh: 1 cup *Caramel Macchiato Latte* membutuhkan **20g Biji Kopi**, **150ml Susu UHT**, **20ml Sirup Karamel**, dan **1 pcs Paper Cup**.
- **Kalkulasi HPP Otomatis**: Sistem menghitung total modal pokok (HPP) produk jadi secara dinamis berdasarkan akumulasi harga beli bahan-bahan penyusunnya!
- **Otomatisasi Potong Bahan Baku saat Checkout Kasir**:
  - Ketika kasir menjual produk beresep, sistem **otomatis memotong stok seluruh bahan baku penyusunnya** di cabang bersangkutan dan mencatat mutasi stok dengan tipe `BOM_DEDUCTION`!

### 5. Manajemen Stok CRUD & Kartu Pergerakan (Stock Movement Ledger)
- **Saldo Stok Cabang**:
  - Menampilkan stok fisik seluruh Barang Jadi dan Bahan Baku per cabang.
  - Peringatan stok menipis (*Low Stock Alert*) bila stok $\le$ batas minimum reorder.
- **Penyesuaian Stok (Stock Adjustment)**:
  - Form mutasi: Tambah barang masuk pembelian (`PURCHASE`), kurangi stok rusak/hilang (`ADJUSTMENT_DAMAGE`), atau koreksi opname (`ADJUSTMENT_OPNAME`).
- **Buku Kartu Pergerakan Stok (Movement Ledger)**:
  - Riwayat lengkap: Waktu, Nama Item, Tipe Pergerakan, Qty Perubahan (+/-), Saldo Sebelum, Saldo Sesudah, No Referensi/Invoice, dan Nama Petugas.

### 6. Modul Penjualan (POS Kasir)
- Sesi Shift Kasir (Buka Shift dengan Modal Awal & Tutup Shift dengan Rekonsiliasi Kas Fisik).
- Katalog produk sentuh cepat, filter kategori, dan pencarian instan barcode.
- Multi-Metode Bayar: Tunai (dengan kalkulator kembalian otomatis), QRIS dinamis, Debit EDC, Transfer.
- Cetak Struk thermal 58mm/80mm instan.

### 7. Laporan Penjualan (Sales Reports)
- Ringkasan KPI: Penjualan Bersih, Total HPP, Laba Kotor Penjualan, Margin (%), Jumlah Transaksi, AOV.
- 10 Produk Terlaris & Kontribusi Laba.
- Filter tanggal, cabang, dan metode bayar.

### 8. Employee Self Service (ESS) & Integrasi Laba Rugi
- **Presensi Geofencing GPS**: Perhitungan jarak Haversine terhadap lokasi toko (radius 100m) disertai simulator GPS (12m vs 250m) untuk pengujian.
- **Komisi Kasir**: Pelacakan akumulasi omzet harian kasir dan komisi (1.5%).
- **Reimbursement Kas Kecil Toko**:
  - Staf mengajukan klaim dana talangan darurat toko (kertas kasir, token listrik).
  - Saat dicairkan, nominal **otomatis ter-posting ke Beban Operasional Cabang dan LANGSUNG MENGOREKSI Laba Bersih pada Laporan Laba Rugi**.

### 9. Pelanggan & Mitra CRM (Kategori, Kontrak Mitra & Harga Antar Cabang)
- **Data Pelanggan Tersimpan (CRUD Master Pelanggan)**:
  - Menyimpan kode (`CUST-001`, `MTR-001`), nama lengkap / PT / CV, kontak WhatsApp, email, alamat, latitude, longitude, kategori, diskon bawaan (%), dan catatan khusus.
- **Kategori Pelanggan & Diferensiasi Penjualan**:
  - `REGULER`: Penjualan ritel umum (`RETAIL`), harga standar cabang.
  - `MEMBER`: Penjualan loyalitas member (`MEMBER_SALE`), harga tier member atau diskon 10%.
  - `RESELLER`: Penjualan grosir agen (`RESELLER_SALE`), harga tier reseller atau diskon 20%.
  - `MITRA`: Penjualan B2B rekanan korporat (`MITRA_B2B`), harga kontrak khusus mitra.
- **Harga Kontrak Khusus Mitra B2B (Setiap Mitra Harga Jual Berbeda)**:
  - Sistem memiliki tabel `mitra_custom_prices` yang mengunci kesepakatan harga per mitra dan produk:
    - Contoh: *Kopi Arabika Gayo 250g* (Ritel Rp 75.000):
      - **Mitra A** (PT Mitra Bahtera Abadi): **Rp 25.000/pcs**
      - **Mitra B** (CV Mitra Nusantara Sejahtera): **Rp 23.500/pcs** (karena kontrak volume besar).
- **Harga Jual Berbeda Antar Cabang (Produk Sama, Harga Berbeda Tiap Branch)**:
  - Setiap cabang dapat menetapkan harga jual lokal (`local_price` di `branch_stocks`) untuk produk yang sama:
    - *Caramel Macchiato*:
      - Cabang Jakarta (Grand Indonesia): **Rp 35.000**
      - Cabang Bandung (Paris Van Java): **Rp 34.000**
      - Cabang Surabaya (Tunjungan Plaza): **Rp 32.000**
- **Otomatisasi Kasir POS**:
  - Pemilihan pelanggan di POS otomatis memperbarui kategori, menampilkan badge diskon, dan **menghitung ulang (*instant recalculate*) harga keranjang** mengikuti hirarki kontrak mitra / tier pelanggan secara real-time.

### 10. Kurir, Biaya Kirim Dinamis, Peta Geospasial & Armada Motor
- **Titik Maps Geospasial Outlet Cabang & Pelanggan (Leaflet.js)**:
  - Outlet cabang memiliki titik koordinat GPS acuan keberangkatan (`latitude`, `longitude`).
  - Pelanggan dan mitra memiliki titik GPS tujuan (dilengkapi tombol **📍 GPS Saya** otomatis).
  - Peta interaktif Leaflet merender pin lokasi cabang (merah), pin pelanggan (biru), dan garis rute pengiriman (*geodesic polyline*).
- **Biaya Kirim Dinamis Berbasis Jarak (Haversine Formula)**:
  - Jarak tempuh garis lurus dihitung presisi menggunakan formula geodetik Haversine.
  - Tarif ongkir per cabang dapat diatur dinamis (`delivery_settings`):
    - *Base Fee (Tarif Dasar)*: Rp 12.000 untuk 3 KM pertama.
    - *Tarif Tambahan*: Rp 3.000 per KM berikutnya.
    - *Gratis Ongkir Otomatis*: Belanja $\ge$ Rp 200.000 otomatis bebas ongkir (Rp 0).
  - Kasir cukup mengaktifkan toggle *"🛵 Antar Kurir (Delivery)"* di keranjang belanja kasir.
- **Armada Kurir & Catatan Bensin BBM Berbasis Kilometer Motor**:
  - Master armada motor kurir dengan nomor polisi, driver, dan **rasio konsumsi BBM yang dapat diatur fleksibel** (`fuel_ratio`: misal 38 km/L atau rasio meter/liter).
  - Catatan bensin kurir (`vehicle_fuel_logs`) mencatat: Odometer sebelum, odometer saat isi BBM, jarak tempuh ($\Delta\text{KM}$), liter dibeli, harga per liter, total biaya, dan indikator efisiensi BBM (Normal/Irit vs Boros).
  - **Otomatisasi Akuntansi**: Pembelian bensin **langsung masuk ke `branch_expenses` kategori Beban Bahan Bakar (BBM) Kurir**.
- **Servis Kendaraan Kurir Terintegrasi ke Beban Laporan Laba Rugi**:
  - Formulir riwayat perawatan armada (`vehicle_service_logs`): Tanggal servis, Odometer servis, Jenis perawatan (Ganti Oli Rutin, Tune Up, Kampas Rem, CVT, Ban), Nama bengkel, dan Biaya servis.
  - **Posting Otomatis ke Laba Rugi**: Biaya servis otomatis masuk ke `branch_expenses` kategori `Beban Servis & Perawatan Armada Kurir` dan **LANGSUNG MEMOTONG Laba Bersih Cabang secara real-time**.

### 11. Varian Pilihan Produk Pemotong Bahan Baku (Dual-Layer Variant BOM)
- **Konsep Resep Dua Lapis (Dual-Layer BOM Deduction Engine)**:
  - **Lapisan 1 (Base BOM)**: Memotong bahan baku utama produk dari `product_recipes`.
  - **Lapisan 2 (Variant BOM)**: Memotong bahan baku pelengkap spesifik dari `variant_recipes` sesuai varian yang dipilih saat kasir memilih item.
- **Kasus Nyata (Air Mineral Galon 19 Liter `PRD-GLN-19L`)**:
  - *Base Recipe*: 19 Liter Air Baku RO (`RAW-AIR-RO`).
  - **Varian 1: "Pakai Tutup Segel (+Rp 1.000)"** (`VAR-GLN-TUTUP`):
    - Terhubung ke 1 pcs Tutup Galon Segel Higienis (`RAW-CAP-GLN`) & 1 pcs Tisu Sanitasi Pembersih Leher Galon (`RAW-TISU-GLN`).
    - *Hasil Mutasi*: **Memotong stok galon, memotong air baku 19L, MEMOTONG stok tutup 1 pcs, dan MEMOTONG stok tisu 1 pcs**.
  - **Varian 2: "Tanpa Tutup (Refill Saja)"** (`VAR-GLN-NOTUTUP`):
    - 0 entri bahan di `variant_recipes`.
    - *Hasil Mutasi*: **Memotong stok galon dan air baku 19L, namun STOK TUTUP DAN TISU SAMA SEKALI TIDAK BERKURANG (selisih 0)**, sementara penjualan galon tetap tercatat normal dengan harga refill standar.
- **Antarmuka Kasir POS Interaktif**:
  - Produk dengan varian memiliki penanda visual `✨ Ada Varian`. Saat diklik, modal pemilih varian terbuka menampilkan rincian harga dan bahan baku yang akan dipotong.
  - Keranjang belanja mencatat nama varian spesifik (misal: `Air Mineral Galon 19 Liter [Pakai Tutup Segel]`).
- **Tab 6: Formula Resep & BOM**:
  - Sub-tab 1: Resep Produk Utama (Base BOM).
  - Sub-tab 2: Resep Pilihan Varian (Variant BOM) lengkap dengan formulir tambah bahan varian dan kalkulasi HPP tambahan per varian.

### 12. Fleksibilitas Penetapan Harga Varian & CRUD Lengkap di Sub-Tab Resep Pilihan Varian
- **Opsi Varian dengan Tambahan Harga (`additional_price > 0`)**:
  - Pelanggan yang memilih opsi varian bertingkat atau premium dikenakan biaya tambahan di atas harga dasar produk.
  - *Contoh*:
    - Varian *Pakai Tutup Segel* (`VAR-GLN-TUTUP`): Tambahan Harga `+Rp 1.000` $\rightarrow$ Harga Jual menjadi `Rp 20.000`.
    - Varian *Ukuran Large 16oz* (`PRD-001-LRG`): Tambahan Harga `+Rp 8.000` $\rightarrow$ Harga Jual menjadi `Rp 43.000`.
  - Ditandai dengan badge hijau interaktif di POS dan kartu BOM: `+Rp 1.000 (Ada Tambahan Harga)`.
- **Opsi Varian Tanpa Tambahan Harga (`additional_price = 0` / Harga Normal)**:
  - Pelanggan yang memilih varian standar tidak dikenakan biaya tambahan apa pun.
  - *Contoh*:
    - Varian *Tanpa Tutup (Refill Saja)* (`VAR-GLN-NOTUTUP`): Tambahan Harga `+Rp 0` $\rightarrow$ Harga Jual tetap `Rp 19.000` (murni harga dasar cabang/tier).
    - Varian *Ukuran Regular 12oz* (`PRD-001-REG`): Tambahan Harga `+Rp 0` $\rightarrow$ Harga Jual tetap normal.
  - Ditandai dengan badge abu-abu di POS dan kartu BOM: `+Rp 0 (Tanpa Tambahan Biaya / Normal)`.
- **CRUD Lengkap di Sub-Tab 2: Resep Pilihan Varian (Variant BOM)**:
  - **Create**: Tombol `➕ Tambah Varian Baru` di header sub-tab memungkinkan penambahan varian baru untuk produk jadi apa pun dengan memilih produk induk, nama varian, SKU, dan tambahan harga/HPP.
  - **Read**: Kartu varian menampilkan rincian produk induk, SKU varian, badge status harga, formula bahan baku pelengkap, dan kalkulasi HPP.
  - **Update**: Tombol `Edit` pada kartu varian memungkinkan revisi nama, SKU, dan perubahan nominal tambahan harga (berbayar vs gratis).
  - **Delete**: Tombol ikon tempat sampah merah (`trash-2`) menghapus varian dan relasinya secara tuntas.
  - **Manage Bahan Baku**: Tombol `+ Bahan` memungkinkan penambahan bahan baku dari katalog `RAW_MATERIAL` dengan takaran dan satuan yang sesuai.
- **Sinkronisasi Otomatis**:
  - Setiap perubahan varian pada sub-tab Resep Pilihan Varian otomatis tersinkronisasi ke katalog kasir POS dan tabel Master Produk.

---

## 🎨 Arsitektur Tampilan Responsif & Navigasi Sidebar Kiri Presisi

Untuk menjamin kenyamanan operasional dan fleksibilitas di berbagai ukuran layar:
1. **Sidebar Kiri Presisi (Left Sidebar Navigation)**:
   - Ditempatkan di sisi kiri dengan 6 kategori menu terstruktur (*Transaksi & Kasir, Operasional & SDM, Katalog & Inventaris, Pelanggan & Mitra B2B, Logistik & Kurir, Akuntansi Finansial*).
   - Dilengkapi kartu profil pengguna login dan tombol logout instan di footer sidebar.
2. **Fitur Collapse & Expand (Desktop & Tablet)**:
   - **Mode Luas (`w-64` / 256px)**: Menampilkan nama modul lengkap dengan nomor urut jelas (1 s.d. 10) dan icon Lucide.
   - **Mode Ringkas (`w-20` / 80px)**: Dapat di-*collapse* dengan menekan ikon toggle di sudut atas sidebar (`panel-left-close`). Menghasilkan area kerja POS kasir yang lebih luas dan fokus.
3. **Mobile Drawer Responsif (< 768px)**:
   - Sidebar bertransformasi menjadi *Off-Canvas Drawer* tersembunyi dengan tombol pemicu hamburger di *header*.
   - Dilengkapi *Backdrop Overlay* gelap blur (`bg-slate-900/60 backdrop-blur-sm`). Memilih menu otomatis menutup drawer kembali.
4. **Bilah Utilitas Atas (Sticky Topbar)**:
   - Judul layar dan subtitle deskriptif tersinkronisasi otomatis dengan modul yang sedang dibuka.
   - Dropdown pemilih cabang (*Branch Switcher*) aktif di semua halaman.
   - Status shift kasir real-time (*Live Shift Badge* dengan animasi pulse).

---

## 🧪 Hasil Uji Coba & Verifikasi Fitur

| Skenario Uji | Tindakan / Input | Hasil Pengujian | Status |
| :--- | :--- | :--- | :---: |
| **Login Kredensial & Quick Demo** | Masuk via form login / tombol Super Admin, Kasir, Manager | Berhasil login, sesi tersimpan, user diarahkan ke dashboard POS. | **PASS** |
| **Sidebar Collapse / Expand** | Klik tombol toggle di samping logo sistem | Lebar sidebar berganti mulus antara 256px dan 80px, label teks tersembunyi/muncul presisi. | **PASS** |
| **Sidebar Mobile Drawer** | Buka pada resolusi smartphone, klik menu hamburger | Drawer muncul di atas kanvas dengan backdrop blur; klik menu otomatis menutup drawer. | **PASS** |
| **BOM Raw Material Deduction** | Checkout transaksi kasir untuk produk beresep (Caramel Macchiato) | Bahan baku (Biji Kopi, Susu, Sirup, Cup) terpotong otomatis di kartu pergerakan stok cabang. | **PASS** |
| **ESS Reimbursement ke P&L** | Buat klaim kas kecil via ESS, ubah status ke Disbursed | Nilai beban langsung masuk ke baris beban operasional Laba Rugi dan mengoreksi Laba Bersih. | **PASS** |
| **CRUD Karyawan & PIN** | Tambah kasir baru dengan NIK dan PIN 6 digit | Karyawan tersimpan di database SQLite dan dapat digunakan untuk login/buka shift kasir. | **PASS** |
| **Data Pelanggan & Titik Maps GPS**| Simpan pelanggan baru dengan latitude & longitude via form CRM | Koordinat GPS tersimpan di database dan pin lokasi muncul di peta Leaflet. | **PASS** |
| **Diferensiasi Harga Mitra A vs B** | Bandingkan produk Kopi Gayo pada Mitra A vs Mitra B di POS & Tab CRM | Mitra A Rp 25.000, Mitra B Rp 23.500 (klausul `mitra_custom_prices` aktif). | **PASS** |
| **Harga Produk Berbeda Antar Cabang** | Bandingkan harga PRD-001 saat beralih cabang (Jakarta vs Surabaya vs Bandung) | Jakarta Rp 35.000, Surabaya Rp 32.000, Bandung Rp 34.000 terisolasi presisi. | **PASS** |
| **Titik Maps Outlet & Pelanggan**| Akses subtab peta geospasial Leaflet | Titik outlet asal dan titik pelanggan tujuan terpetakan dengan garis rute geodesic. | **PASS** |
| **Kalkulasi Biaya Kirim Dinamis**| Hitung ongkir jarak 2.5 km (Base Rp 12k) vs 5.8 km (Rp 21k) | Algoritma Haversine dan tarif per KM menghitung ongkir dinamis akurat. | **PASS** |
| **Promo Bebas Ongkir (Threshold)**| Belanja Rp 250.000 pada cabang ber-threshold Rp 200.000 | Biaya kirim otomatis terdiskon menjadi Rp 0 (Gratis Ongkir). | **PASS** |
| **Log BBM Berbasis Odometer KM** | Catat pengisian 3L bensin untuk jarak 114 km (rasio 38 km/L) | Odometer terupdate, efisiensi 100%, dan **otomatis masuk Beban BBM Kurir**. | **PASS** |
| **Servis Armada Masuk Beban P&L** | Catat servis ganti oli dan kampas rem Rp 135.000 di AHASS | **Otomatis ter-posting ke Beban Servis Armada Kurir dan memotong Laba Bersih di Laporan Laba Rugi**. | **PASS** |
| **Checkout Transaksi Delivery POS**| Centang toggle Antar Kurir dan checkout belanjaan | Grand total menyertakan ongkir, armada kurir tertaut, dan struk mencatat biaya antar. | **PASS** |
| **Varian BOM: Galon Pakai Tutup** | Checkout 2x Galon 19L dengan varian "Pakai Tutup Segel" | Galon terpotong 2 pcs, Air Baku RO terpotong 38L, **Tutup Segel terpotong 2 pcs, Tisu terpotong 2 pcs**. | **PASS** |
| **Varian BOM: Galon Tanpa Tutup** | Checkout 3x Galon 19L dengan varian "Tanpa Tutup (Refill Saja)" | Galon terpotong 3 pcs, Air Baku RO terpotong 57L, **Stok Tutup Segel & Tisu TETAP 0 (tidak tersentuh)**, transaksi normal. | **PASS** |
| **Varian Berbayar (+Rp 1.000 / +Rp 8.000)** | Checkout Galon Varian Tutup (+Rp 1.000) atau Kopi Large (+Rp 8.000) | Harga satuan terhitung akurat: Base Price + Tambahan Harga Varian. Subtotal dan Grand Total presisi (`test_variant_pricing.php`). | **PASS** |
| **Varian Bebas Biaya (+Rp 0 / Harga Tetap)** | Checkout Galon Varian Tanpa Tutup (+Rp 0) atau Kopi Regular (+Rp 0) | Harga satuan tetap sama dengan Base Price (Rp 19.000). Tidak ada penambahan biaya sepeser pun. | **PASS** |
| **CRUD Varian Dinamis via UI & API** | Buat varian baru berbayar (+Rp 5.000) dan varian gratis (+Rp 0) | Data varian tersimpan di tabel `product_variants`, muncul di POS dengan badge sesuai. | **PASS** |
| **CRUD Varian di Sub-Tab Resep Pilihan Varian** | Tambah varian (+Rp 3.500) langsung di sub-tab BOM, edit jadi (+Rp 0), uji POS, dan hapus | Seluruh alur Create, Read, Update, Delete & transaksi teruji tuntas (`test_variant_crud_bom.php`). | **PASS** |


