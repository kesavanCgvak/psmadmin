<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Negotiation Cancelled - Pro Subrental Marketplace</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace"
                    style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding: 25px;">
                <p>
                    Hello there,<br>
                </p>
                <h4>The negotiation for <strong style="color: #1a73e8;">{{ $rental_job_name }}</strong>
                    has been cancelled.</h4>

                <table width="100%" cellpadding="8" cellspacing="0"
                    style="background: #f1f5fb; border-radius: 8px; margin-top: 20px;">
                    <h4 style="color: #1a73e8; margin-top: 0;">Offer Details</h4>
                    <tr>
                        <td><strong>Sender:</strong></td>
                        <td>{{ $sender }}</td>
                    </tr>
                    <tr>
                        <td><strong>Receiver:</strong></td>
                        <td>{{ $receiver }}</td>
                    </tr>
                    <tr>
                        <td><strong>Amount:</strong></td>
                        <td>{{ $total_price }}</td>
                    </tr>
                    <tr>
                        <td><strong>Reason:</strong></td>
                        <td>{{ $reason }}</td>
                    </tr>
                    <tr>
                        <td><strong>Date:</strong></td>
                        <td>{{ $date }}</td>
                    </tr>
                </table>
                <br>

                {!! $products_section !!}

                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> connects rental companies to help maximize equipment
                    utilization and simplify collaboration within the rental industry.
                </p>
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
