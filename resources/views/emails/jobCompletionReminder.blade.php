<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Complete Job - Pro Subrental Marketplace</title>
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
                <h4 style="color: #1a73e8; margin-top: 0;">Reminder: Please complete this job</h4>
                <p>
                    Hello there,
                </p>
                <p>
                    The unpack date for <strong style="color: #1a73e8;">{{ $rental_job_name }}</strong> was
                    <strong>{{ $unpack_date }}</strong>. Please update the job status to <strong>Completed</strong> in the app so the renter can leave a rating.
                </p>
                <p>
                    This is a {{ $reminder_label }} reminder ({{ $days_since_unpack }} days after unpack).
                </p>
                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> connects rental companies to help maximize equipment
                    utilization and simplify collaboration within the rental industry.
                </p>
            </td>
        </tr>

        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; {{ date('Y') }} Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>

</html>
