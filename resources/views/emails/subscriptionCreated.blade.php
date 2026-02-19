<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Subscription Created - Pro Subrental Marketplace</title>
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
                <h2 style="color: #1a73e8; margin-top: 0;">Your subscription is set up</h2>
                <p>Hi {{ $username }},</p>
                <p>Thanks for registering with Pro Subrental Marketplace. Here are your subscription details:</p>
                <table width="100%" cellpadding="8" cellspacing="0" style="background: #f1f5fb; border-radius: 8px; margin-top: 20px;">
                    <tr>
                        <td><strong>Plan</strong></td>
                        <td>{{ $plan_name }}</td>
                    </tr>
                    <tr>
                        <td><strong>Status</strong></td>
                        <td>{{ ucfirst($status) }}</td>
                    </tr>
                    @if($amount)
                        <tr>
                            <td><strong>Billing</strong></td>
                            <td>{{ $currency }} {{ number_format((float) $amount, 2) }} {{ $interval ? '/ ' . $interval : '' }}</td>
                        </tr>
                    @endif
                    @if($trial_end_date)
                        <tr>
                            <td><strong>Trial ends</strong></td>
                            <td>{{ $trial_end_date }}</td>
                        </tr>
                    @endif
                </table>

                <p style="margin-top: 20px;">You can manage your account anytime.</p>
                <p style="text-align: center; margin: 24px 0;">
                    <a href="{{ $app_url ?? env('APP_FRONTEND_URL') }}" style="display: inline-block; padding: 14px 28px; background-color: #e8d50b; color: #000000; font-size: 16px; font-weight: bold; text-decoration: none; border-radius: 6px;">Go to Dashboard</a>
                </p>

                <p style="margin-top: 10px;">If you did not expect this email, please contact our support team.</p>
                <p>Thank you,<br>Pro Subrental Marketplace Team</p>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; {{ date('Y') }} Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>
</html>

