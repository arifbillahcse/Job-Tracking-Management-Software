/* Job Tracker - small, dependency-free UI behaviours. No inline scripts are used (strict CSP). */
(function () {
    'use strict';

    // Disable a form's submit button after the first submit to prevent double posts.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-loading')) {
            return;
        }
        var button = form.querySelector('button[type="submit"]');
        if (button && !button.disabled) {
            // Defer so the browser still includes the button's value in the submission.
            window.setTimeout(function () {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            }, 0);
        }
    });

    // Confirm before a destructive action: <button data-confirm="Delete this job?">
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-confirm]');
        if (trigger && !window.confirm(trigger.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    // Job form: choosing a trip fills in the trip's foreman (the server applies the same rule when saving).
    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-trip-select]');
        if (!select) {
            return;
        }
        var option = select.options[select.selectedIndex];
        var foremanId = option ? option.getAttribute('data-foreman') : '';
        var target = document.querySelector(select.getAttribute('data-foreman-target') || '');
        if (target && foremanId) {
            target.value = foremanId;
        }
    });

    // Trip entries: Addio = Grand - Job Beg, Total to BH = Job Beg - Depo + Addio (the client's sheet formulas), live while typing.
    document.addEventListener('input', function (event) {
        var input = event.target;
        var kind = input && input.getAttribute ? input.getAttribute('data-calc') : null;
        if (kind !== 'grand' && kind !== 'job_beg' && kind !== 'depo') {
            return;
        }
        var scope = input.closest('tr') || input.closest('form');
        if (!scope) {
            return;
        }
        var cents = function (name) {
            var el = scope.querySelector('[data-calc="' + name + '"]');
            return el && el.value !== '' ? Math.round(parseFloat(el.value) * 100) || 0 : 0;
        };
        var set = function (name, value) {
            var el = scope.querySelector('[data-calc="' + name + '"]');
            if (el) {
                el.value = (value / 100).toFixed(2);
            }
        };
        var addio = cents('grand') - cents('job_beg');
        set('addio', addio);
        set('total_to_bh', cents('job_beg') - cents('depo') + addio);
    });

    // Job form: Total to BH = Grand total - Deposit; Balance due = Total to BH - Paid (the client's rules), live while typing.
    document.addEventListener('input', function (event) {
        var input = event.target;
        var kind = input && input.getAttribute ? input.getAttribute('data-money') : null;
        if (kind !== 'grand_total' && kind !== 'deposit' && kind !== 'paid') {
            return;
        }
        var form = input.closest('form');
        var cents = function (name) {
            var el = form.querySelector('[data-money="' + name + '"]');
            return el && el.value !== '' ? Math.round(parseFloat(el.value) * 100) || 0 : 0;
        };
        var set = function (name, value) {
            var el = form.querySelector('[data-money="' + name + '"]');
            if (el) {
                el.value = (value / 100).toFixed(2);
            }
        };
        var bh = cents('grand_total') - cents('deposit');
        set('bh_total', bh);
        set('balance', bh - cents('paid'));
    });

    // Desktop: hide / show the left menu (remembered in this browser). A wide Jobs table then gets about 250 px more room.
    (function () {
        var root = document.documentElement;
        var button = document.getElementById('menu-toggle');
        if (!button) {
            return;
        }
        var paint = function (hidden) {
            root.classList.toggle('jt-menu-hidden', hidden);
            button.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            button.setAttribute('aria-label', hidden ? 'Show the menu' : 'Hide the menu');
        };
        var saved = false;
        try { saved = window.localStorage.getItem('jt-menu-hidden') === '1'; } catch (e) { saved = false; }
        paint(saved);
        button.addEventListener('click', function () {
            var hidden = !root.classList.contains('jt-menu-hidden');
            paint(hidden);
            try { window.localStorage.setItem('jt-menu-hidden', hidden ? '1' : '0'); } catch (e) { /* not remembered */ }
        });
    })();

    // <a data-open-details="#id"> opens a <details> block and moves to it.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-open-details]');
        var block = trigger ? document.querySelector(trigger.getAttribute('data-open-details')) : null;
        if (block) {
            block.open = true;
        }
    });

    // Show / hide a password field: <button data-toggle-password="#password">
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-toggle-password]');
        if (!button) {
            return;
        }
        var input = document.querySelector(button.getAttribute('data-toggle-password'));
        if (!input) {
            return;
        }
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Hide' : 'Show';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });

    // Copy text to the clipboard: <button data-copy="#elementId">
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button) {
            return;
        }
        var source = document.querySelector(button.getAttribute('data-copy'));
        if (!source || !navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(source.textContent.trim()).then(function () {
            var original = button.innerHTML;
            button.textContent = 'Copied';
            window.setTimeout(function () { button.innerHTML = original; }, 1500);
        });
    });

    // Open a dialog on page load: <span data-auto-open-modal="statusModal-in_transit" hidden></span>
    document.addEventListener('DOMContentLoaded', function () {
        var marker = document.querySelector('[data-auto-open-modal]');
        if (!marker || !window.bootstrap) {
            return;
        }
        var modal = document.getElementById(marker.getAttribute('data-auto-open-modal'));
        if (modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    });

    // Warn before leaving a form with unsaved edits: <form data-unsaved>
    (function () {
        var dirty = false;
        document.addEventListener('input', function (event) {
            if (event.target.closest && event.target.closest('form[data-unsaved]')) {
                dirty = true;
            }
        });
        document.addEventListener('submit', function () {
            dirty = false;
        });
        window.addEventListener('beforeunload', function (event) {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    })();

    // After a failed save, mark invalid fields for assistive technology and move focus to the first one.
    document.addEventListener('DOMContentLoaded', function () {
        var invalid = document.querySelectorAll('.is-invalid');
        invalid.forEach(function (field) {
            field.setAttribute('aria-invalid', 'true');
        });
        if (invalid.length > 0 && typeof invalid[0].focus === 'function') {
            invalid[0].focus({ preventScroll: false });
        }
    });

    // Re-enable submit buttons when the page is restored from the back/forward cache.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            document.querySelectorAll('form[data-loading] button[type="submit"]').forEach(function (button) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            });
        }
    });
})();
