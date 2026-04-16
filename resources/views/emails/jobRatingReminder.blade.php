<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Reminder: Rate Your Completed Job - Pro Subrental Marketplace</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace"
                    style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
            </td>
        </tr>

        <tr>
            <td style="padding: 25px;">
                <h4 style="color: #1a73e8; margin-top: 0;">Reminder: Please rate your completed job</h4>
                <p>Hello there,</p>
                <p>
                    <strong>{{ $provider_name }}</strong> marked the job <strong style="color: #1a73e8;">{{ $rental_job_name }}</strong> as completed on {{ $completed_date }}. We’d still love your feedback.
                </p>
                <p>This is a {{ $reminder_label }} reminder ({{ $days_since_completed }} days since completion).</p>
                <p style="font-size: 14px;">Please log in to your account to rate this job. Your feedback helps improve our marketplace.</p>
                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> connects rental companies to help maximize equipment utilization and simplify collaboration within the rental industry.
                </p>
            </td>
        </tr>

        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; {{ $current_year }} Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>

</html>
