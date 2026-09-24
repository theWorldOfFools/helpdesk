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
    <?php endif; ?>
    <script src="assets/js/script.js"></script>
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
