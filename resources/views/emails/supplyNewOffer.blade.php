<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New Offer from {{ $provider_name }}</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">
        <!-- Header with Logo -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="prosubmarket.com" style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
            </td>
        </tr>

        <!-- Content -->
        <tr>
            <td style="padding: 24px 28px;">
                <h2 style="margin: 0 0 12px 0; color:#2d3748; font-weight: 600; font-size: 20px;">New Offer Received</h2>

                <p style="margin: 0 0 10px 0;">Hello,</p>
                <p style="margin: 0 0 16px 0; line-height: 1.5;">You’ve received a new offer from <strong>{{ $provider_name }}</strong> for your rental request <strong>{{ $rental_job_name }}</strong>.</p>

                <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 12px 0 18px 0;">
                    <tr>
                        <td style="padding: 10px 12px; border: 1px solid #e5e7eb; background:#f9fafb; width: 40%; font-weight: 600;">Offer Amount</td>
                        <td style="padding: 10px 12px; border: 1px solid #e5e7eb;">{{ $currency_symbol }}{{ $amount }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 12px; border: 1px solid #e5e7eb; background:#f9fafb; font-weight: 600;">Sent At</td>
                        <td style="padding: 10px 12px; border: 1px solid #e5e7eb;">{{ $sent_at }}</td>
                    </tr>
                </table>

                <p style="margin: 0 0 18px 0;">Login to your account to review and respond to this offer.</p>

                <a href="{{ url('/') }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 10px 16px; border-radius: 4px; font-weight: 600;">Login to Respond</a>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; 2025 Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>

</html>
