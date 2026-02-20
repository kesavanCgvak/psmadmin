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
                <h2 style="color: #d32f2f; margin-top: 0;">{{ $is_immediate ? 'Your subscription has been canceled' : 'Subscription cancellation confirmed' }}</h2>
                <p>Hi {{ $username }},</p>
                
                @if($is_immediate)
                    <p>We're sorry to see you go. Your subscription has been canceled and your access will end immediately.</p>
                @else
                    <p>We've received your request to cancel your subscription. Your subscription will remain active until the end of your current billing period.</p>
                @endif

                <table width="100%" cellpadding="8" cellspacing="0" style="background: #fff3e0; border-radius: 8px; margin-top: 20px; border-left: 4px solid #ff9800;">
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
                    @if($current_period_end && !$is_immediate)
                        <tr>
                            <td><strong>Service continues until</strong></td>
                            <td style="color: #1976d2; font-weight: bold;">{{ $current_period_end }}</td>
                        </tr>
                    @endif
                </table>

                @if(!$is_immediate && $current_period_end)
                    <div style="background-color: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin: 0;"><strong>Important:</strong> Your subscription will remain active until <strong>{{ $current_period_end }}</strong>. You'll continue to have full access to all features until then.</p>
                    </div>
                @endif

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
                &copy; {{ date('Y') }} Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>
</html>

