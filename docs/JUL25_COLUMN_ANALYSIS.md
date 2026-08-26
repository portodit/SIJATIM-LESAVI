# Invoice JUL25 - Struktur Kolom & Formula Analysis

**File:** `7.13 PRINT FINAL INVOIS TELKOM JULI2025 (1) (2).xlsx`
**Sheet:** JUL25
**Total Baris:** 812 (1 header + 1 sub-header + 810 data)
**Total Kolom:** 35

---

## 📊 Mapping Kolom Excel ke Tabel Database

| Kolom | Header L1 | Header L2 | Excel Col | Type | Sub Tahap | Tabel | Keterangan |
|-------|-----------|-----------|----------|------|-----------|-------|------------|
| 1 | NO. | A | G | VALUE | 1.1 | `invoice_lines.no` | Nomor urut |
| 2 | JENIS KANTOR | B | H | VALUE | 1.1 | `invoice_lines.jenis_kantor_snapshot` | Snapshot nama Jenis Kantor |
| 3 | ALAMAT | C | I | FORMULA | 1.1 | `invoice_lines.alamat_snapshot` | `=XLOOKUP(G4,DATABASE!...)` |
| 4 | KATEGORI LOKASI | D | J | FORMULA | 1.1 | `invoice_lines.kategori_snapshot` | `=XLOOKUP(G4,DATABASE!...)` |
| 5 | LAYANAN | E | K | VALUE | 1.1 | `invoice_lines.layanan_snapshot` | |
| 6 | TYPE ORDER | F | L | VALUE | 1.2 | `invoice_lines.type_order_id` | FK ke type_orders |
| 7 | ID | G | M | VALUE | 1.1 | `invoice_lines.bank_jatim_link_id` | FK ke bank_jatim_links (SSID) |
| 8 | PERIOD | H | N | VALUE | 1.2 | `invoice_lines.period` | Contoh: JUL25 |
| 9 | Bandwidth | Eksisting BW | I | VALUE | 1.3 | `invoice_bandwidths.existing_bw_id` | FK ke bandwidth_options |
| 10 | | Upgrade BW | J | VALUE | 1.3 | `invoice_bandwidths.upgrade_bw_id` | FK ke bandwidth_options |
| 11 | | Downgrade BW | K | VALUE | 1.3 | `invoice_bandwidths.downgrade_bw_id` | FK ke bandwidth_options |
| 12 | IP Host Telkom | IP Host Telkom | L | VALUE | 1.1 | `invoice_lines.ip_host_snapshot` | Snapshot IP Host |
| 13 | TANGGAL AKTIVASI | TANGGAL MULAI AKTIF | M | VALUE | 1.1 | `invoice_lines.activation_date_snapshot` | Tanggal aktivasi awal |
| 14 | TANGGAL MULAI AKTIF LAYANAN (UPGRADE/DOWNGRADE) | | N | VALUE | 1.2 | `invoice_lines.effective_date` | Tanggal efektif upgrade/downgrade |
| 15 | PEMAKAIAN AVERAGE (Kbps) | Eksisting | O | VALUE | 1.5 | `invoice_traffic_usages.usage_avg_existing_kbps` | Input manual |
| 16 | | Upgrade | P | VALUE | 1.5 | `invoice_traffic_usages.usage_avg_upgrade_kbps` | Input manual |
| 17 | TRAFIK TOTAL (Kbps) | Eksisting | Q | FORMULA | 1.5 | `invoice_traffic_usages.traffic_total_existing_kbps` | `=(O4*24*3600*30/8)/1000000` |
| 18 | | Upgrade | R | FORMULA | 1.5 | `invoice_traffic_usages.traffic_total_upgrade_kbps` | `=(P4*24*3600*30/8)/1000000` |
| 19 | DURASI DOWNTIME | (Jam) | S | VALUE | 1.7 | `invoice_slas.downtime_hours` | Input manual |
| 20 | SLA | SLA ACTUAL | T | FORMULA | 1.7 | `invoice_slas.sla_actual` | `=(V4-S4)/V4` |
| 21 | | SLA PKS | U | VALUE | 1.7 | `invoice_slas.sla_pks` | Input manual |
| 22 | Availability (Jam/bulan) | | V | VALUE | 1.7 | `invoice_slas.availability_hours` | Input manual |
| 23 | NILAI RESTITUSI (Rp) | Restitusi Tagihan | W | VALUE | 1.8 | `invoice_restitutions.restitusi_tagihan` | Input/Calculated |
| 24 | | Restitusi Over SLA | X | FORMULA | 1.8 | `invoice_restitutions.restitusi_over_sla` | `=IF(T4<99%,((S4-V4)/720)*Y4,0)` |
| 25 | HARGA (Rp) | Eksisting Harga | Y | VALUE | 1.6 | `invoice_prices.harga_eksisting` | Lookup price_master |
| 26 | | Upgrade Harga | Z | VALUE | 1.6 | `invoice_prices.harga_upgrade` | Lookup price_master |
| 27 | | Downgrade Harga | AA | VALUE | 1.6 | `invoice_prices.harga_downgrade` | Lookup price_master |
| 28 | | Cabut (Harga Prorate) | AB | VALUE | 1.6 | `invoice_prices.harga_cabut_prorate` | Calculated |
| 29 | | PSB | AC | VALUE | 1.6 | `invoice_prices.harga_psb` | Lookup price_master |
| 30 | | Relokasi | AD | VALUE | 1.6 | `invoice_prices.harga_relokasi` | Lookup price_master |
| 31 | | TOTAL | AE | FORMULA | 1.6 | `invoice_prices.total` | `=SUM(Y4:AD4)` |
| 32 | HARGA SETELAH RESTITUSI | | AF | FORMULA | 1.8 | `invoice_restitutions.total_after_restitusi` | `=IFERROR(AE4+X4,0)` |
| 33 | SUB TOTAL PER CABANG | | AG | FORMULA | Summary | Aggregated | `=SUM(AE4:AE26)` |
| 34 | MIKROTIK/NONMIKROTIK | | AH | VALUE | Support | `invoice_lines.mikrotik_status` | MIKROTIK / NONMIKROTIK |
| 35 | KETERANGAN | | AI | VALUE | Support | `invoice_lines.notes` | Catatan |

---

## 🔢 Ringkasan: Formula vs Value

### Kolom dengan FORMULA (9 buah)

| No | Kolom | Formula | Fungsi |
|----|-------|---------|--------|
| 1 | 3 (ALAMAT) | `=XLOOKUP(G4,DATABASE!$B$2:$B$806,DATABASE!$C...)` | Lookup alamat dari sheet DATABASE |
| 2 | 4 (KATEGORI) | `=XLOOKUP(G4,DATABASE!$B$2:$B$806,DATABASE!$D...)` | Lookup kategori dari sheet DATABASE |
| 3 | 17 (TRAFIK Existing) | `=(O4*24*3600*30/8)/1000000` | Konversi Usage Avg ke Trafik Total |
| 4 | 18 (TRAFIK Upgrade) | `=(P4*24*3600*30/8)/1000000` | Konversi Usage Avg ke Trafik Total |
| 5 | 20 (SLA ACTUAL) | `=(V4-S4)/V4` | Hitung SLA: (Availability - Downtime) / Availability |
| 6 | 24 (Restitusi Over SLA) | `=IF(T4<99%,((S4-V4)/720)*Y4,0)` | Hitung restitusi jika SLA < 99% |
| 7 | 31 (TOTAL HARGA) | `=SUM(Y4:AD4)` | Sum semua komponen harga |
| 8 | 32 (HARGA SETELAH RESTITUSI) | `=IFERROR(AE4+X4,0)` | Total + Restitusi |
| 9 | 33 (SUB TOTAL) | `=SUM(AE4:AE26)` | Sum per kelompok Jenis Kantor |

### Kolom VALUE (26 buah)

**Input Manual:**
- Type Order, Period, Bandwidth (3 kolom)
- Tanggal Aktivasi, Tanggal Efektif
- Usage Average (2 kolom)
- Durasi Downtime, SLA PKS, Availability
- Restitusi Tagihan
- Harga (6 kolom)
- Mikrotik/Nonmikrotik, Keterangan

**Lookup/Referensi:**
- Alamat, Kategori, Layanan (dari DATABASE sheet)
- Harga (dari price_master berdasarkan bandwidth)

---

## 📁 Struktur Child Tables

### 1. `invoice_lines` (Core)
```sql
- id
- invoice_period_id (FK)
- bank_jatim_link_id (FK ke SSID)
- no (nomor urut)

-- Snapshot data saat invoice dibuat:
- jenis_kantor_snapshot
- alamat_snapshot
- kategori_snapshot
- layanan_snapshot
- ip_host_snapshot
- activation_date_snapshot

-- Sub Tahap 1.2:
- type_order_id (FK)
- period
- effective_date

-- Support:
- mikrotik_status
- notes
```

### 2. `invoice_bandwidths` (Sub Tahap 1.3)
```sql
- id
- invoice_line_id (FK)
- existing_bw_id (FK ke bandwidth_options)
- upgrade_bw_id (FK ke bandwidth_options, nullable)
- downgrade_bw_id (FK ke bandwidth_options, nullable)
```

### 3. `invoice_traffic_usages` (Sub Tahap 1.3/1.5)
```sql
- id
- invoice_line_id (FK)
- usage_avg_existing_kbps
- usage_avg_upgrade_kbps
- traffic_total_existing_kbps (calculated: formula 17)
- traffic_total_upgrade_kbps (calculated: formula 18)
```

### 4. `invoice_slas` (Sub Tahap 1.7)
```sql
- id
- invoice_line_id (FK)
- downtime_hours
- sla_actual (calculated: formula 20)
- sla_pks
- availability_hours
```

### 5. `invoice_restitutions` (Sub Tahap 1.8)
```sql
- id
- invoice_line_id (FK)
- restitusi_tagihan
- restitusi_over_sla (calculated: formula 24)
- total_after_restitusi (calculated: formula 32)
```

### 6. `invoice_prices` (Sub Tahap 1.6)
```sql
- id
- invoice_line_id (FK)
- harga_eksisting
- harga_upgrade
- harga_downgrade
- harga_cabut_prorate
- harga_psb
- harga_relokasi
- total (calculated: formula 31)
```

---

## 📝 Catatan Penting

1. **Formula di Excel** = kolom yang dihitung otomatis oleh sistem
2. **Value di Excel** = kolom yang diinput manual atau lookup dari tabel lain
3. **Snapshot fields** di `invoice_lines` penting agar data bulan lalu tidak berubah saat master diupdate
4. **Kolom 33 (SUB TOTAL)** adalah aggregated value per Jenis Kantor, bukan per baris

---

## 🔗 Relasi Antar Tabel

```
invoice_periods (1)
    └── invoice_lines (many)
            ├── invoice_bandwidths (1)
            ├── invoice_traffic_usages (1)
            ├── invoice_slas (1)
            ├── invoice_restitutions (1)
            └── invoice_prices (1)
```

---

*Generated: 2026-07-01*
*Source: 7.13 PRINT FINAL INVOIS TELKOM JULI2025 (1) (2).xlsx*
