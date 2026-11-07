        </main>
        <?php if (!empty($currentUser)): ?>
        <footer class="text-center text-secondary small py-3 border-top bg-white">
            <span>&copy; <?php echo date('Y'); ?> Helpdesk IT · Sistem Tiket Operasional Divisi IT</span>
        </footer>
        <?php else: ?>
        </div>
        <?php endif; ?>
    <?php if (!empty($currentUser)): ?>
    </div>
</div>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!empty($currentUser)): ?>
    <?php
    // Dialog pengumuman global: tampil untuk semua user yang login.
    require_once __DIR__ . '/announcement.php';
    $announcementConn = getDBConnection();
    $announcementItems = getActiveAnnouncementsForUser($announcementConn, (int)$currentUser['id']);
    pg_close($announcementConn);
    ?>
    <?php if (!empty($announcementItems)): ?>
    <div class="modal fade" id="announcementModal" tabindex="-1" aria-labelledby="announcementModalLabel" aria-hidden="true"
         data-bs-backdrop="static" data-bs-keyboard="false"
         data-announcement-ids="<?php echo e(implode(',', array_map(function ($a) { return (int)$a['id']; }, $announcementItems))); ?>"
         data-dismiss-url="announcement_dismiss.php">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-danger text-white">
                    <h2 class="modal-title h5 d-flex align-items-center gap-2" id="announcementModalLabel">
                        <i class="bi bi-megaphone-fill"></i> Pengumuman
                    </h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <?php foreach ($announcementItems as $announcementIndex => $announcement): ?>
                        <?php if ($announcementIndex > 0): ?><hr class="my-4"><?php endif; ?>
                        <article>
                            <h3 class="h6 fw-bold mb-1"><?php echo e($announcement['judul']); ?></h3>
                            <?php if (!empty($announcement['created_at'])): ?>
                            <div class="small text-secondary mb-2">
                                <i class="bi bi-clock me-1"></i><?php echo date('d M Y H:i', strtotime($announcement['created_at'])); ?>
                            </div>
                            <?php endif; ?>
                            <div class="announcement-content"><?php echo renderAnnouncementBody($announcement['isi']); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer justify-content-between align-items-center">
                    <small class="text-secondary"><i class="bi bi-info-circle me-1"></i>Tidak akan tampil lagi selama 24 jam.</small>
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Mengerti</button>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/announcement-modal.js"></script>
    <?php endif; ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.1.8/js/dataTables.bootstrap5.min.js"></script>
    <script>
      // Flag kesiapan DataTables; tabel PHP fallback tetap tampil bila CDN gagal
      window.__dtReady = !!(window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable);
    </script>
    <script src="assets/js/datatables-grids.js"></script>
    <?php if (($activePage ?? '') === 'dashboard.php'): ?>
    <script src="assets/js/dashboard-charts.js"></script>
    <?php endif; ?>
    <?php if (($activePage ?? '') === 'laporan.php'): ?>
    <script src="assets/js/reports.js"></script>
    <?php endif; ?>
    <?php if (in_array($activePage ?? '', ['view_ticket.php', 'view_cr.php', 'view_task.php'], true)): ?>
    <!-- Editor markdown + gambar untuk komentar / tindak lanjut -->
    <script src="assets/vendor/toastui/toastui-editor-all.min.js"></script>
    <script src="assets/js/helpdesk-markdown-editor.js"></script>
    <script src="assets/js/comment-editor.js"></script>
    <?php endif; ?>
    <?php endif; ?>
    <script src="assets/js/script.js"></script>
    <script src="assets/js/pwa-install.js"></script>
    <script>
      // PWA: daftarkan service worker (aset statis saja, halaman dinamis tidak di-cache)
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
          navigator.serviceWorker.register('sw.js').catch(function () {});
        });
      }
    </script>
</body>
</html>
