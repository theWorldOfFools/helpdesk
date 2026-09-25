<?php
// Download template XLS import oncall. Format: Nama | Tanggal(No 1-31).
// Bulan diambil dari konteks form import (kolom tanggal hanya nomor).
require_once 'config.php';
requireLogin();

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('Library export belum terinstal. Jalankan: composer install');
}
require_once __DIR__ . '/vendor/autoload.php';

$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Jadwal Oncall');
$sh->fromArray([['Nama', 'Tanggal']], null, 'A1');
$sh->fromArray([
    ['Teknisi Satu', 1],
    ['Teknisi Dua', 2],
    ['Teknisi Satu', 5],
    ['Teknisi Dua', 5],
], null, 'A2');
$sh->getStyle('A1:B1')->getFont()->setBold(true);
$sh->getColumnDimension('A')->setWidth(30);
$sh->getColumnDimension('B')->setWidth(14);
$sh->getComment('B1')->getText()->createTextRun(
    'Isi NOMOR tanggal saja (1-31). Bulan dipilih di form import. ' .
    'Hari biasa maks 1 nama per nomor; Minggu/tanggal merah boleh 2 nama (dua baris nomor sama).'
);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template-jadwal-oncall.xlsx"');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
exit;
