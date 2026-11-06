<script>
    (function () {
        if (window.emailLogActionLoader) {
            return;
        }

        var active = false;

        function root() {
            return document.getElementById('emailLogActionLoader');
        }

        function setButtonsDisabled(disabled) {
            document.querySelectorAll('.js-email-retry-form button[type="submit"]').forEach(function (button) {
                button.disabled = disabled;
            });
        }

        window.emailLogActionLoader = {
            isActive: function () {
                return active;
            },
            show: function () {
                var overlay = root();
                if (!overlay) {
                    return;
                }

                active = true;
                overlay.hidden = false;
                overlay.classList.add('is-active');
                overlay.setAttribute('aria-busy', 'true');
                document.body.classList.add('email-log-action-locked');
                setButtonsDisabled(true);
            },
            hide: function () {
                var overlay = root();
                active = false;

                if (overlay) {
                    overlay.classList.remove('is-active');
                    overlay.hidden = true;
                    overlay.setAttribute('aria-busy', 'false');
                }

                document.body.classList.remove('email-log-action-locked');
                setButtonsDisabled(false);
                document.querySelectorAll('.js-email-retry-form').forEach(function (form) {
                    form.dataset.submitting = '0';
                });
            },
            track: function (work) {
                var loader = this;
                loader.show();

                return Promise.resolve().then(work).then(function (result) {
                    loader.hide();
                    return result;
                }, function (error) {
                    loader.hide();
                    throw error;
                });
            }
        };

        document.addEventListener('submit', function (event) {
            if (!active) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
        }, true);

        document.addEventListener('keydown', function (event) {
            if (!active) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
        }, true);

        window.addEventListener('pageshow', function () {
            window.emailLogActionLoader.hide();
        });
    })();

    $(document).on('submit', '.js-email-retry-form', function (event) {
        var form = this;

        if (window.emailLogActionLoader && window.emailLogActionLoader.isActive()) {
            event.preventDefault();
            return false;
        }

        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return false;
        }

        if (!window.confirm('Resend this email to the original recipient? A successful submission only means the mail server accepted it. It does not confirm the recipient received it.')) {
            return false;
        }

        form.dataset.submitting = '1';
        $(form).find('button[type="submit"]').prop('disabled', true);
        $('.js-email-retry-form button[type="submit"]').prop('disabled', true);

        if (window.emailLogActionLoader) {
            window.emailLogActionLoader.show();
        }

        return true;
    });
</script>
