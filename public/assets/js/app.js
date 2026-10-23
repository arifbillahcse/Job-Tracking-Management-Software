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
