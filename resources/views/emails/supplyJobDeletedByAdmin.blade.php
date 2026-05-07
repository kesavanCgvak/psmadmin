<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Supply Job Deleted by Admin - Pro Subrental Marketplace</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 25px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 700px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace"
                    style="max-width: 200px; height: auto;">
            </td>
        </tr>

        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8;">Supply Job Deleted by Admin</h2>
                <p>Hello,</p>

                <p>
                    A supply job associated with <strong>{{ $supply_job_name }}</strong> has been deleted by the admin.
                </p>

                <p><strong>Provider:</strong> {{ $provider }}</p>
                <p><strong>Status:</strong> {{ $status }}</p>
                <p><strong>Date:</strong> {{ $date }}</p>

                {!! $reason_display !!}
                {!! $products_section !!}

                <p style="margin-top: 30px;">
                    Regards,<br>
                    <strong>Pro Subrental Marketplace Team</strong>
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
