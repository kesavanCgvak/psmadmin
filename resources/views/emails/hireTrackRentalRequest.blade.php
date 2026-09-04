<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>New HireTrack Rental Request - Pro Subrental Marketplace</title>
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
                <h2 style="color: #1a73e8; margin-top: 0;">New Rental Request</h2>

                <p>
                    Hello <strong>{{ $provider_contact_name }}</strong>,<br><br>
                    You have received a new rental request through
                    <strong>Pro Subrental Marketplace</strong>.
                </p>

                <h3 style="color: #1a73e8; margin-top: 30px;">Rental Details</h3>
                <p style="margin-bottom: 10px;">
                    <strong>Rental Name:</strong> {{ $rental_name }}<br>
                    <strong>Rental Dates:</strong> {{ $from_date }} to {{ $to_date }}<br>
                    <strong>Shipping Method:</strong> {{ $shipping_method }}<br>
                    <strong>Delivery Address:</strong> {{ $delivery_address }}<br>
                    <strong>Requester:</strong> {{ $user_name }} ({{ $user_company }})
                </p>

                {!! $global_message_section !!}

                {!! $offer_requirements_section !!}

                {!! $private_message_section !!}

                {!! $csv_note !!}

                {!! $skipped_products_section !!}

                <p style="margin-top: 25px;">
                    Thank you,<br>
                    <strong>Pro Subrental Marketplace</strong>
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
