<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Subscription Canceled - Pro Subrental Marketplace</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">
        <!-- Header -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace" style="max-width: 200px; height: auto;">
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #d32f2f; margin-top: 0;">{{ $heading }}</h2>
                <p>Hi {{ $username }},</p>

                <p>{{ $cancellation_message }}</p>

                <table width="100%" cellpadding="8" cellspacing="0" style="background: #fff3e0; border-radius: 8px; margin-top: 20px; border-left: 4px solid #ff9800;">
                    <tr>
                        <td><strong>Plan</strong></td>
                        <td>{{ $plan_name }}</td>
                    </tr>
                    <tr>
                        <td><strong>Status</strong></td>
                        <td>{{ $status }}</td>
                    </tr>
                    <tr>
                        <td><strong>Billing</strong></td>
                        <td>{{ $billing_line }}</td>
                    </tr>
                    <tr>
                        <td><strong>Service continues until</strong></td>
                        <td style="color: #1976d2; font-weight: bold;">{{ $service_continues_until }}</td>
                    </tr>
                </table>

                {!! $important_notice !!}

                <p style="margin-top: 20px;">You can reactivate your subscription anytime before it expires, or resubscribe after it ends.</p>
                
                <p style="text-align: center; margin: 24px 0;">
                    <a href="{{ $app_url }}" style="display: inline-block; padding: 14px 28px; background-color: #e8d50b; color: #000000; font-size: 16px; font-weight: bold; text-decoration: none; border-radius: 6px;">Manage Subscription</a>
                </p>

                <p style="margin-top: 20px;">If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
                <p>Thank you for being part of Pro Subrental Marketplace.<br>Pro Subrental Marketplace Team</p>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; {{ $current_year }} Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>
</html>

