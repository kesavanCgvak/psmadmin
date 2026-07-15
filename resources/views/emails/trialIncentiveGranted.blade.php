<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Free Trial Extended - Pro Subrental Marketplace</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace" style="max-width: 200px; height: auto;">
            </td>
        </tr>
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">Your free trial has been extended</h2>
                <p>Hi {{ $user_full_name }},</p>
                <p>
                    Great news! <strong>{{ $company_name }}</strong> has reached a marketplace inventory milestone
                    and earned additional free service time.
                </p>
                <table width="100%" cellpadding="8" cellspacing="0" style="background: #f1f5fb; border-radius: 8px; margin-top: 20px;">
                    <tr>
                        <td><strong>Qualified products</strong></td>
                        <td>{{ $product_count }}</td>
                    </tr>
                    <tr>
                        <td><strong>Bonus months added</strong></td>
                        <td>{{ $bonus_months_applied }}</td>
                    </tr>
                    @if($trial_end_date)
                    <tr>
                        <td><strong>New trial end date</strong></td>
                        <td>{{ $trial_end_date }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td><strong>Milestones earned</strong></td>
                        <td>{{ $grants_summary }}</td>
                    </tr>
                </table>
                <p style="margin-top: 20px;">
                    Keep building your marketplace inventory to unlock more free months, up to 9 months total.
                </p>
                <p style="color: #666; font-size: 13px; margin-top: 30px;">
                    &copy; {{ $current_year }} Pro Subrental Marketplace. All rights reserved.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
