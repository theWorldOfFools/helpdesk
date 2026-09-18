document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.btn-delete').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            const ticketNumber = this.getAttribute('data-ticket');
            if (!confirm('Hapus tiket ' + ticketNumber + '? Apakah Anda yakin?')) {
                e.preventDefault();
            }
        });
    });

    document.querySelectorAll('.status-change').forEach(function (select) {
        select.addEventListener('change', function () {
            const ticketId = this.getAttribute('data-id');
            const newStatus = this.value;
            if (confirm('Ubah status tiket menjadi ' + newStatus + '?')) {
                this.form.submit();
            } else {
                this.value = this.getAttribute('data-old-status');
            }
        });
    });
});
