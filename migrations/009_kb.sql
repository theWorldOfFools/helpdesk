-- Migration: 009 - Knowledge base + template jawaban teknisi
CREATE TABLE IF NOT EXISTS kb_articles (
    id SERIAL PRIMARY KEY,
    judul VARCHAR(255) NOT NULL,
    kategori VARCHAR(100) NOT NULL DEFAULT 'Lainnya',
    isi TEXT NOT NULL,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_kb_kategori ON kb_articles(kategori);

CREATE TABLE IF NOT EXISTS kb_templates (
    id SERIAL PRIMARY KEY,
    judul VARCHAR(255) NOT NULL,
    isi TEXT NOT NULL,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Template bawaan (dibuat atas nama admin id=1 bila ada)
INSERT INTO kb_templates (judul, isi, created_by) VALUES
('Minta info tambahan', 'Mohon info tambahan agar bisa ditindaklanjuti: 1) Kapan tepatnya kejadian? 2) Pesan error persis seperti apa? 3) Apakah terjadi di perangkat lain juga? Terima kasih.', 1),
('Konfirmasi selesai', 'Perbaikan sudah dilakukan dan dites. Mohon konfirmasi apakah kendala sudah teratasi di sisi Anda. Jika sudah beres, tiket akan kami tutup. Terima kasih.', 1),
('Eskalasi — butuh sparepart/akses', 'Dari hasil pengecekan, penanganan butuh tindak lanjut tambahan (sparepart/akses vendor). Estimasi selesai menyusul dan akan dikabari perkembangannya. Mohon bersabar.', 1)
ON CONFLICT DO NOTHING;

INSERT INTO kb_articles (judul, kategori, isi, created_by) VALUES
('WiFi putus-putus di ruang meeting', 'Jaringan', '<p><strong>Gejala:</strong> WiFi terhubung tapi tidak ada internet, berulang.</p><p><strong>Langkah:</strong></p><ol><li>Cek LED access point, restart bila merah.</li><li>Pastikan kabel LAN ke switch terkunci.</li><li>Ganti channel ke 1/6/11 bila interferensi.</li><li>Eskalasi ke Network bila > 2 AP terdampak.</li></ol>', 1),
('Printer offline padahal menyala', 'Hardware', '<p><strong>Gejala:</strong> status printer offline di Windows.</p><p><strong>Langkah:</strong></p><ol><li>Matikan SNMP pada port printer (Printer Properties > Port > Configure).</li><li>Restart Print Spooler.</li><li>Pastikan IP printer tidak berubah/dhcp conflict.</li></ol>', 1),
('Outlook tidak sinkron', 'Software', '<p><strong>Langkah:</strong></p><ol><li>Cek koneksi dan kredensial SSO.</li><li>Hapus profil mail dan buat ulang bila OST corrupt.</li><li>Batasi mailbox cache 6 bulan bila mailbox besar.</li></ol>', 1)
ON CONFLICT DO NOTHING;
