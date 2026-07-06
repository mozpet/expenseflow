import { Receipt, StrukApproval, Invoice, AuditLog, NotificationItem, AppSettings } from './types';

export const initialReceipts: Receipt[] = [
  {
    id: 'R-101',
    karyawan: 'Rizky Dian',
    initials: 'RD',
    avatarBg: 'bg-rose-100',
    avatarColor: 'text-rose-700',
    merchant: 'Resto Padang',
    ocrNominal: 185000,
    klaim: 1850000,
    kategori: 'Makan',
    status: 'Review',
    tanggal: '26 Mei 2026',
    departemen: 'Marketing'
  },
  {
    id: 'R-102',
    karyawan: 'Andi L',
    initials: 'AL',
    avatarBg: 'bg-amber-100',
    avatarColor: 'text-amber-700',
    merchant: 'Parkir Senayan',
    ocrNominal: 25000,
    klaim: 250000,
    kategori: 'Parkir',
    status: 'Review',
    tanggal: '26 Mei 2026',
    departemen: 'Sales'
  },
  {
    id: 'R-103',
    karyawan: 'Budi W',
    initials: 'BW',
    avatarBg: 'bg-emerald-100',
    avatarColor: 'text-emerald-700',
    merchant: 'Indomaret BSD',
    ocrNominal: 187500,
    klaim: 187500,
    kategori: 'ATK',
    status: 'Pending',
    tanggal: '26 Mei 2026',
    departemen: 'Operations'
  },
  {
    id: 'R-104',
    karyawan: 'Nita F',
    initials: 'NF',
    avatarBg: 'bg-blue-100',
    avatarColor: 'text-blue-700',
    merchant: 'Grab dinas',
    ocrNominal: 95000,
    klaim: 95000,
    kategori: 'Transport',
    status: 'Pending',
    tanggal: '25 Mei 2026',
    departemen: 'Sales'
  }
];

export const initialStrukApprovals: StrukApproval[] = [
  {
    id: 'RA-01',
    karyawan: 'Diana Putri',
    merchant: 'Grab Food',
    nominal: 320000,
    keputusan: 'Disetujui',
    diprosesOleh: 'Sari Rahma',
    waktu: '25 Mei, 14:20',
    catatan: '—'
  },
  {
    id: 'RA-02',
    karyawan: 'Hendra K',
    merchant: 'SPBU Pertamina',
    nominal: 300000,
    keputusan: 'Ditolak',
    diprosesOleh: 'Sari Rahma',
    waktu: '24 Mei, 10:05',
    catatan: 'Struk tidak terbaca'
  },
  {
    id: 'RA-03',
    karyawan: 'Mega Sari',
    merchant: 'Hotel Aston',
    nominal: 1250000,
    keputusan: 'Disetujui',
    diprosesOleh: 'Sari Rahma',
    waktu: '23 Mei, 09:30',
    catatan: '—'
  },
  {
    id: 'RA-04',
    karyawan: 'Joko S',
    merchant: 'Resto XYZ',
    nominal: 2500000,
    keputusan: 'Ditolak',
    diprosesOleh: 'Sari Rahma',
    waktu: '22 Mei, 16:40',
    catatan: 'Variance +950%, dugaan manipulasi'
  },
  {
    id: 'RA-05',
    karyawan: 'Fitri H',
    merchant: 'Indomaret',
    nominal: 87000,
    keputusan: 'Disetujui',
    diprosesOleh: 'Sari Rahma',
    waktu: '21 Mei, 11:15',
    catatan: '—'
  }
];

export const initialInvoices: Invoice[] = [
  {
    id: 'INV-0042',
    vendor: 'PT Maju Jaya',
    total: 18500000,
    jatuhTempo: '26 Mei 2026',
    kategori: 'Software',
    sumber: 'Scan',
    status: 'Due',
    catatan: 'Segera proses pembayaran Adobe All Apps',
    npwp: '12.345.678.9-000.000',
    tanggalInv: '20 Mei 2026',
    ppn: 1833333,
    keterangan: 'Adobe CC All Apps subscription renewal.',
    sha256Hash: 'b7d3e1f2a9c8d5e7a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a1b2c3d4e5f6',
    uploadOleh: 'Sari Rahma',
    waktuUpload: '26 Mei 2026, 09:41',
    items: [
      { id: '1', deskripsi: 'Adobe CC All Apps', qty: 1, harga: 16666667, subtotal: 16666667 }
    ]
  },
  {
    id: 'INV-0041',
    vendor: 'CV Berkah',
    total: 12750000,
    jatuhTempo: '2 Jun 2026',
    kategori: 'Percetakan',
    sumber: 'Manual',
    status: 'Pending',
    catatan: 'Cetak brosur marketing',
    npwp: '11.222.333.4-001.000',
    tanggalInv: '25 Mei 2026',
    ppn: 1100000,
    keterangan: 'Pencetakan marketing kit & brosur.',
    sha256Hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    uploadOleh: 'Sari Rahma',
    waktuUpload: '26 Mei 2026, 09:30',
    items: [
      { id: '1', deskripsi: 'Pencetakan Brosur Lipat A4 Glossy', qty: 5000, harga: 2330, subtotal: 11650000 }
    ]
  },
  {
    id: 'INV-0040',
    vendor: 'PT Sumber Makmur',
    total: 6950000,
    jatuhTempo: '5 Jun 2026',
    kategori: 'Logistik',
    sumber: 'Scan',
    status: 'Pending',
    catatan: 'Pengiriman barang kantor',
    npwp: '14.555.666.1-002.000',
    tanggalInv: '18 Mei 2026',
    ppn: 688738,
    keterangan: 'Pengiriman armada logistik log-019',
    sha256Hash: 'b5d4f1a268fc8c442aa4649b9322e70e41b2e3f7c469275de5c49f1b78521a22',
    uploadOleh: 'Sari Rahma',
    waktuUpload: '25 Mei 2026, 17:00',
    items: [
      { id: '1', deskripsi: 'Jasa Cargo Logistik PT Sumber Makmur', qty: 1, harga: 6261262, subtotal: 6261262 }
    ]
  }
];

export const initialHistoryInvoices: Invoice[] = [
  {
    id: 'INV-0039',
    vendor: 'CV Digital',
    total: 8750000,
    jatuhTempo: '20 Mei 2026',
    kategori: 'Software',
    sumber: 'Manual',
    status: 'Dibayar',
    catatan: 'Sesuai PO-0035',
    tanggalInv: '10 Mei 2026',
    uploadOleh: 'Sari Rahma'
  },
  {
    id: 'INV-0038',
    vendor: 'PT Logistik',
    total: 4200000,
    jatuhTempo: '18 Mei 2026',
    kategori: 'Logistik',
    sumber: 'Manual',
    status: 'Dibayar',
    catatan: '2-level approval',
    tanggalInv: '8 Mei 2026',
    uploadOleh: 'Sari Rahma'
  },
  {
    id: 'INV-0037',
    vendor: 'CV Berkah',
    total: 6000000,
    jatuhTempo: '15 Mei 2026',
    kategori: 'Percetakan',
    sumber: 'Manual',
    status: 'Ditolak',
    catatan: 'Vendor tidak terdaftar',
    tanggalInv: '5 Mei 2026',
    uploadOleh: 'Sari Rahma'
  },
  {
    id: 'INV-0036',
    vendor: 'PT Sumber M',
    total: 22000000,
    jatuhTempo: '12 Mei 2026',
    kategori: 'Logistik',
    sumber: 'Scan',
    status: 'Dibayar',
    catatan: '>Rp20jt, 3-level',
    tanggalInv: '12 Mei 2026',
    uploadOleh: 'Sari Rahma'
  },
  {
    id: 'INV-0035',
    vendor: 'CV Maju',
    total: 3500000,
    jatuhTempo: '10 Mei 2026',
    kategori: 'Lainnya',
    sumber: 'Manual',
    status: 'Dibayar',
    catatan: '—',
    tanggalInv: '1 Mei 2026',
    uploadOleh: 'Sari Rahma'
  }
];

export const initialAuditLogs: AuditLog[] = [
  {
    id: 'LOG-001',
    iconBg: 'bg-green-600',
    title: 'INV-0042 — disetujui Finance Manager',
    details: '26 Mei 2026, 10:30 · Sari Rahma · Rp 18.500.000 · PT Maju Jaya',
    waktu: '26 Mei 2026, 10:30'
  },
  {
    id: 'LOG-002',
    iconBg: 'bg-blue-600',
    title: 'INV-0042 — scan & OCR selesai, data dikunci',
    details: '26 Mei 2026, 09:41 · SHA256: b7d3e1f2... · Upload: Sari Rahma',
    waktu: '26 Mei 2026, 09:41'
  },
  {
    id: 'LOG-003',
    iconBg: 'bg-amber-600',
    title: 'Rizky Dian — variance terdeteksi (+900%)',
    details: '26 Mei 2026, 09:55 · OCR: Rp 185.000 vs Klaim: Rp 1.850.000 · Auto-flag',
    waktu: '26 Mei 2026, 09:55'
  },
  {
    id: 'LOG-004',
    iconBg: 'bg-rose-600',
    title: 'INV-0037 — ditolak (vendor tidak terdaftar)',
    details: '15 Mei 2026, 09:00 · Ditolak oleh Sari Rahma · CV Berkah',
    waktu: '15 Mei 2026, 09:00'
  },
  {
    id: 'LOG-005',
    iconBg: 'bg-green-600',
    title: 'INV-0036 — disetujui 3-level (Dir + Komisaris)',
    details: '12 Mei 2026, 14:45 · Total Rp 22.000.000 · PT Sumber Makmur',
    waktu: '12 Mei 2026, 14:45'
  },
  {
    id: 'LOG-006',
    iconBg: 'bg-blue-600',
    title: 'Budi Wicaksono — struk disubmit',
    details: '26 Mei 2026, 09:41 · Indomaret BSD · Rp 187.500 · SHA256: a3f8c2...',
    waktu: '26 Mei 2026, 09:41'
  }
];

export const initialNotifications: NotificationItem[] = [
  {
    id: 'NT-01',
    type: 'due',
    title: 'Invoice jatuh tempo hari ini — INV-0042',
    subtitle: 'PT Maju Jaya · Rp 18.500.000 · Segera proses pembayaran.',
    time: 'Baru saja',
    read: false
  },
  {
    id: 'NT-02',
    type: 'flag',
    title: 'Variance terdeteksi — Rizky Dian',
    subtitle: 'Klaim Rp 1.850.000 vs OCR Rp 185.000 (+900%). Segera review.',
    time: '2 jam lalu',
    read: false
  },
  {
    id: 'NT-03',
    type: 'new',
    title: 'Invoice baru masuk — INV-0041',
    subtitle: 'CV Berkah Percetakan · Rp 12.750.000 · Via input manual.',
    time: '3 jam lalu',
    read: false
  },
  {
    id: 'NT-04',
    type: 'new',
    title: 'Struk baru — Budi Wicaksono',
    subtitle: 'Indomaret BSD · Rp 187.500 · Data konsisten, siap review.',
    time: '5 jam lalu',
    read: false
  },
  {
    id: 'NT-05',
    type: 'success',
    title: 'INV-0039 berhasil dibayar',
    subtitle: 'CV Digital · Rp 8.750.000 · Bukti transfer tersimpan.',
    time: '1 hari lalu',
    read: true
  }
];

export const defaultSettings: AppSettings = {
  varianceLimit: 10,
  maxClaimLimit: 2000000,
  thresholdSingle: '< Rp 10.000.000',
  thresholdTwo: 'Rp 10 jt — Rp 50 jt',
  thresholdThree: '> Rp 50.000.000'
};
