<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Offer from {{ $provider_name }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333;">
    <h2 style="color:#2d3748;">New Offer Received</h2>

    <p>Hello,</p>

    <p>You’ve received a new offer from <strong>{{ $provider_name }}</strong> for your rental request <strong>#{{ $rental_job_id }}</strong>.</p>

    <table style="border-collapse: collapse; margin: 15px 0;">
        <tr>
            <td style="padding: 6px 12px; border: 1px solid #ddd;">Offer Amount</td>
            <td style="padding: 6px 12px; border: 1px solid #ddd;">₹{{ $amount }}</td>
        </tr>
        <!-- <tr>
            <td style="padding: 6px 12px; border: 1px solid #ddd;">Version</td>
            <td style="padding: 6px 12px; border: 1px solid #ddd;">{{ $version }}</td>
        </tr> -->
        <tr>
            <td style="padding: 6px 12px; border: 1px solid #ddd;">Sent At</td>
            <td style="padding: 6px 12px; border: 1px solid #ddd;">{{ $sent_at }}</td>
        </tr>
    </table>

    <p>Login to your account to review and respond to this offer.</p>

    <p>—<br><strong>Pro Subrental Marketplace</strong></p>
</body>
</html>
