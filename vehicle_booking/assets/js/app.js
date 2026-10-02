/* Shared behaviour for Vehicle Booking pages. Loaded from partials/head.php. */

/*
 * Default Back button handler. Pages may still define their own goBack();
 * a later function declaration replaces this one. Previously several pages
 * called goBack() without defining it (or only defined it conditionally),
 * so the Back button did nothing.
 */
function goBack() {
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1 && window.history.length > 1) {
        window.history.back();
        return;
    }
    var home = document.querySelector('.main-navbar .navbar-brand');
    window.location.href = home ? home.getAttribute('href') : 'login.php';
}

/*
 * Clickable request rows.
 *
 * Any <tr data-request-id="123"> opens the shared "Request Details" popup,
 * filled from request_details.php (trip info, who actioned it, activity
 * trail). Rows with data-vb-modal="#someModal" open that page modal instead
 * (e.g. an approve/reject modal). Inside any modal, an empty
 * <div data-vb-details="123"></div> is filled with the same details when the
 * modal opens. Clicks on buttons, links and form fields inside a row, and
 * rows marked .editing, are left alone.
 */
(function () {
    function loadDetails(el, id) {
        if (el.dataset.loaded === '1') return;
        el.innerHTML = '<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
        fetch('request_details.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (res) {
                if (res.redirected) throw new Error('session');  // idle timeout sends us to login.php
                return res.text();
            })
            .then(function (html) { el.innerHTML = html; el.dataset.loaded = '1'; })
            .catch(function (err) {
                el.innerHTML = err && err.message === 'session'
                    ? '<div class="alert alert-warning mb-0">Your session has expired. Please <a href="login.php">log in</a> again.</div>'
                    : '<div class="alert alert-danger mb-0">Could not load request details.</div>';
            });
    }

    function sharedModal() {
        var modal = document.getElementById('vbRequestDetailsModal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'vbRequestDetailsModal';
        modal.tabIndex = -1;
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">' +
              '<div class="modal-content text-start">' +
                '<div class="modal-header bg-primary text-white">' +
                  '<h5 class="modal-title"><i class="fa fa-car me-2"></i>Request Details</h5>' +
                  '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>' +
                '</div>' +
                '<div class="modal-body"></div>' +
                '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modal);
        return modal;
    }

    window.vbShowRequestDetails = function (id) {
        var modal = sharedModal();
        var body = modal.querySelector('.modal-body');
        body.dataset.loaded = '';
        loadDetails(body, id);
        bootstrap.Modal.getOrCreateInstance(modal).show();
    };

    document.addEventListener('click', function (ev) {
        var tr = ev.target.closest('tr[data-request-id]');
        if (!tr || tr.classList.contains('editing')) return;
        if (ev.target.closest('a, button, input, select, textarea, label, .no-row-click')) return;
        var target = tr.dataset.vbModal && document.querySelector(tr.dataset.vbModal);
        if (target) {
            bootstrap.Modal.getOrCreateInstance(target).show();
        } else {
            window.vbShowRequestDetails(tr.dataset.requestId);
        }
    });

    // Keyboard access: Enter on a focused row opens it.
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') return;
        var tr = ev.target.closest && ev.target.closest('tr[data-request-id]');
        if (tr && ev.target === tr) tr.click();
    });

    document.addEventListener('show.bs.modal', function (ev) {
        ev.target.querySelectorAll('[data-vb-details]').forEach(function (el) {
            loadDetails(el, el.dataset.vbDetails);
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('tr[data-request-id]').forEach(function (tr) {
            if (!tr.hasAttribute('tabindex')) tr.tabIndex = 0;
        });
    });
})();
