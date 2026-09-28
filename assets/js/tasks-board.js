/* Drag-and-drop kartu Board Todo SDLC (native HTML5, tanpa CDN).
   Drop kartu ke kolom fase = pindah fase via api/tasks_move.php (auto-note).
   Gagal/tolak server = kartu dikembalikan + toast/alert. */
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return (m && m.getAttribute('content')) || '';
  }

  function toast(msg, ok) {
    if (window.Notiflix && Notiflix.Notify) {
      if (ok) Notiflix.Notify.success(msg);
      else Notiflix.Notify.failure(msg);
    } else {
      alert(msg);
    }
  }

  function recount() {
    document.querySelectorAll('[data-drop-phase]').forEach(function (col) {
      var badge = document.querySelector('[data-phase-count="' + col.getAttribute('data-drop-phase') + '"]');
      if (badge) badge.textContent = col.querySelectorAll('.task-card').length;
    });
  }

  ready(function () {
    var cards = document.querySelectorAll('.task-card[data-task-id]');
    var cols = document.querySelectorAll('[data-drop-phase]');
    if (!cards.length || !cols.length) return;

    var dragged = null;
    var fromCol = null;
    var busy = {};

    cards.forEach(function (card) {
      card.addEventListener('dragstart', function (ev) {
        dragged = card;
        fromCol = card.closest('[data-drop-phase]');
        card.classList.add('dragging');
        try {
          ev.dataTransfer.effectAllowed = 'move';
          ev.dataTransfer.setData('text/plain', card.getAttribute('data-task-id'));
        } catch (e) { /* abaikan */ }
      });
      card.addEventListener('dragend', function () {
        card.classList.remove('dragging');
        cols.forEach(function (c) { c.classList.remove('drop-hint'); });
        dragged = null;
        fromCol = null;
      });
    });

    cols.forEach(function (col) {
      col.addEventListener('dragover', function (ev) {
        ev.preventDefault();
        try { ev.dataTransfer.dropEffect = 'move'; } catch (e) { /* abaikan */ }
        col.classList.add('drop-hint');
      });
      col.addEventListener('dragleave', function () {
        col.classList.remove('drop-hint');
      });
      col.addEventListener('drop', function (ev) {
        ev.preventDefault();
        col.classList.remove('drop-hint');
        var card = dragged;
        if (!card) return;
        var toPhase = col.getAttribute('data-drop-phase');
        var taskId = card.getAttribute('data-task-id');
        if (card.getAttribute('data-phase') === toPhase) return; // kolom sama: abaikan
        if (busy[taskId]) return;
        busy[taskId] = true;

        // Optimistic UI: pindah dulu, kembalikan bila server menolak
        var origin = fromCol;
        col.appendChild(card);
        card.setAttribute('data-phase', toPhase);
        recount();

        fetch('api/tasks_move.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: parseInt(taskId, 10), to_phase: toPhase, csrf_token: csrfToken() })
        })
          .then(function (res) { return res.json().then(function (j) { return { status: res.status, body: j }; }); })
          .then(function (out) {
            busy[taskId] = false;
            if (out.status === 200 && out.body && out.body.ok) {
              toast('Fase: ' + out.body.from_phase + ' → ' + out.body.to_phase, true);
            } else {
              if (origin) origin.appendChild(card);
              card.setAttribute('data-phase', out.body.from_phase || card.getAttribute('data-phase'));
              recount();
              var msg = (out.body && out.body.error) || 'Gagal memindah kartu.';
              toast(msg, false);
              if (out.status === 401) window.location.href = 'login.php';
            }
          })
          .catch(function () {
            busy[taskId] = false;
            if (origin) origin.appendChild(card);
            recount();
            toast('Jaringan gagal. Kartu dikembalikan.', false);
          });
      });
    });
  });
})();
