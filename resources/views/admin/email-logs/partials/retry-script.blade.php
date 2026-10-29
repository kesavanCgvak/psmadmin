<script>
    $(document).on('submit', '.js-email-retry-form', function () {
        var form = this;

        if (form.dataset.submitting === '1') {
            return false;
        }

        if (!window.confirm('Resend this email to the original recipient? A successful submission only means the mail server accepted it. It does not confirm the recipient received it.')) {
            return false;
        }

        form.dataset.submitting = '1';
        $(form).find('button[type="submit"]').prop('disabled', true);

        return true;
    });
</script>
