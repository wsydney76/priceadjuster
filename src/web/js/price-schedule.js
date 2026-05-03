(function () {
    'use strict';

    function reloadPage(message) {
        sessionStorage.setItem('priceadjuster.notice', message);
        window.location.reload();
    }

    // ── Show stored notice after reload ──────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var notice = sessionStorage.getItem('priceadjuster.notice');
        if (notice) {
            sessionStorage.removeItem('priceadjuster.notice');
            Craft.cp.displayNotice(notice);
        }
    });

    // ── Rule-list: delete all records for a rule ──────────────────────────────
    document.querySelectorAll('.ps-delete-rule-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rule = btn.dataset.rule;
            var total = btn.dataset.total;
            if (!confirm('Delete all ' + total + ' record(s) for rule "' + rule + '"? This cannot be undone.')) {
                return;
            }
            btn.classList.add('loading');
            btn.disabled = true;
            Craft.sendActionRequest('POST', '_priceadjuster/price-schedule/delete-by-rule', {
                data: {rule: rule}
            }).then(function (response) {
                reloadPage(response.data.message || 'Deleted.');
            }).catch(function (e) {
                Craft.cp.displayError((e.response && e.response.data && e.response.data.message) || 'Delete failed.');
                btn.classList.remove('loading');
                btn.disabled = false;
            });
        });
    });

    // ── Rule-detail: select-all toggle ───────────────────────────────────────
    document.querySelectorAll('.ps-select-all').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            var form = toggle.closest('form');
            form.querySelectorAll('.ps-delete-cb').forEach(function (cb) {
                var row = cb.closest('tr');
                if (toggle.checked) {
                    cb.checked = !row || row.style.display !== 'none';
                } else {
                    cb.checked = false;
                }
            });
        });
    });

    // ── Rule-detail: save form + delete selected ──────────────────────────────
    document.querySelectorAll('.price-schedule-form').forEach(function (form) {

        // Save
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('button[type=submit]');
            btn.classList.add('loading');
            btn.disabled = true;
            Craft.sendActionRequest('POST', '_priceadjuster/price-schedule/batch-update', {data: new FormData(form)})
                .then(function (r) {
                    reloadPage(r.data.message || 'Saved.');
                })
                .catch(function (e) {
                    Craft.cp.displayError((e.response && e.response.data && e.response.data.message) || 'An error occurred.');
                })
                .finally(function () {
                    btn.classList.remove('loading');
                    btn.disabled = false;
                });
        });

        // Delete selected
        form.querySelector('.ps-delete-btn').addEventListener('click', function () {
            var checked = Array.from(form.querySelectorAll('.ps-delete-cb:checked'));
            if (!checked.length) {
                Craft.cp.displayError('No rows selected.');
                return;
            }
            if (!confirm('Delete ' + checked.length + ' record(s)? This cannot be undone.')) {
                return;
            }
            var deleteBtn = this;
            deleteBtn.classList.add('loading');
            deleteBtn.disabled = true;
            var ids = checked.map(function (cb) { return cb.value; });
            Craft.sendActionRequest('POST', '_priceadjuster/price-schedule/delete-selected', {data: {ids: ids}})
                .then(function (r) {
                    reloadPage(r.data.message || 'Deleted.');
                })
                .catch(function (e) {
                    Craft.cp.displayError((e.response && e.response.data && e.response.data.message) || 'Delete failed.');
                })
                .finally(function () {
                    deleteBtn.classList.remove('loading');
                    deleteBtn.disabled = false;
                });
        });
    });

    // ── Rule-detail: filter by title (rows only, checkboxes untouched) ─────────
    function clearTitleFilter(form, input, clearBtn) {
        input.value = '';
        form.querySelectorAll('tbody tr').forEach(function (row) {
            row.style.display = '';
        });
        if (clearBtn) { clearBtn.style.display = 'none'; }
    }
    document.querySelectorAll('.ps-select-by-title-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var container = btn.closest('span');
            var input = container ? container.querySelector('.ps-title-filter-input') : null;
            var clearBtn = container ? container.querySelector('.ps-clear-filter-btn') : null;
            var filter = input ? input.value.trim().toLowerCase() : '';
            if (!filter) {
                Craft.cp.displayError('Please enter a title to filter by.');
                if (input) { input.focus(); }
                return;
            }
            var form = btn.closest('form');
            form.querySelectorAll('tbody tr').forEach(function (row) {
                var titleEl = row.querySelector('td strong');
                var title = titleEl ? titleEl.textContent.toLowerCase() : '';
                row.style.display = title.indexOf(filter) !== -1 ? '' : 'none';
            });
            if (clearBtn) { clearBtn.style.display = ''; }
            var scrollTarget = form.previousElementSibling || form;
            if (scrollTarget) { scrollTarget.scrollIntoView({behavior: 'smooth', block: 'start'}); }
        });
    });
    // ── Rule-detail: clear title filter (button or Escape) ────────────────────
    document.querySelectorAll('.ps-clear-filter-btn').forEach(function (clearBtn) {
        clearBtn.addEventListener('click', function () {
            var container = clearBtn.closest('span');
            var input = container ? container.querySelector('.ps-title-filter-input') : null;
            var form = clearBtn.closest('form');
            if (form && input) { clearTitleFilter(form, input, clearBtn); }
        });
    });
    document.querySelectorAll('.ps-title-filter-input').forEach(function (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var container = input.closest('span');
                var filterBtn = container ? container.querySelector('.ps-select-by-title-btn') : null;
                if (filterBtn) { filterBtn.click(); }
                return;
            }
            if (e.key === 'Escape') {
                var container = input.closest('span');
                var clearBtn = container ? container.querySelector('.ps-clear-filter-btn') : null;
                var form = input.closest('form');
                if (form) { clearTitleFilter(form, input, clearBtn); }
            }
        });
    });

    // ── Rule-detail: update effective date ────────────────────────────────────
    document.querySelectorAll('.ps-update-date-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var container = btn.closest('div');
            var input = container.querySelector('.ps-new-date-input');
            var newDate = input ? input.value.trim() : '';
            var rule = input ? input.dataset.rule : '';
            var oldDate = input ? input.dataset.oldDate : '';

            if (!/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
                Craft.cp.displayError('Invalid date format. Expected yyyy-mm-dd.');
                if (input) { input.focus(); }
                return;
            }
            if (!confirm('Change effective date from "' + oldDate + '" to "' + newDate + '" for all pending records in rule "' + rule + '"?')) {
                return;
            }

            btn.classList.add('loading');
            btn.disabled = true;
            Craft.sendActionRequest('POST', '_priceadjuster/price-schedule/update-effective-date', {
                data: {rule: rule, oldDate: oldDate, newDate: newDate}
            }).then(function (r) {
                reloadPage(r.data.message || 'Date updated.');
            }).catch(function (e) {
                Craft.cp.displayError((e.response && e.response.data && e.response.data.message) || 'Update failed.');
                btn.classList.remove('loading');
                btn.disabled = false;
            });
        });
    });
})();

