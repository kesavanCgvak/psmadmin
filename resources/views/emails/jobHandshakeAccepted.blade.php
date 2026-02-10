<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Offer Accepted - Pro Subrental Marketplace</title>
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
                <h4>The offer negotiation for <strong style="color: #1a73e8;">{{ $rental_job_name }}</strong> has been
                    accepted.</h4>

                </p>
                <table width="100%" cellpadding="8" cellspacing="0"
                    style="background: #f1f5fb; border-radius: 8px; margin-top: 20px;">
                    <h4 style="color: #1a73e8; margin-top: 0;">Offer Details</h4>
                    <tr>
                        <td><strong>Sender:</strong></td>
                        <td>{{ $sender ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td><strong>Receiver:</strong></td>
                        <td>{{ $receiver ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td><strong>Amount:</strong></td>
                        <td>{{ $currency_symbol }}{{ $amount }}</td>
                    </tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td style="color: #0fb427ff; font-weight:bold;">Accepted </td>
                    </tr>
                    <tr>
                        <td><strong>Date:</strong></td>
                        <td>{{ $date ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td><strong>Unpack date:</strong></td>
                        <td>{{ $unpacking_date ?? 'To be confirmed' }}</td>
                    </tr>
                </table>
                <br>
                <!-- Products Section -->
                <h3 style="color:#1a73e8; margin-top: 30px;">Product Details</h3>

                <table width="100%" cellpadding="8" cellspacing="0"
                    style="border-collapse: collapse; margin-top: 10px;">
                    <thead>
                        <tr style="background:#e8f0fe; text-align:left;">
                            <th style="border-bottom:1px solid #ccc;">PSM Code</th>
                            <th style="border-bottom:1px solid #ccc;">Model</th>
                            <th style="border-bottom:1px solid #ccc;">Software Code</th>
                            <th style="border-bottom:1px solid #ccc;">Qty</th>
                            <th style="border-bottom:1px solid #ccc;">Price</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>{{ $product['psm_code'] }}</td>
                                <td>{{ $product['model'] }}</td>
                                <td>{{ $product['software_code'] }}</td>
                                <td>{{ $product['accepted_quantity'] }}</td>
                                <td>{{ $currency_symbol ?? '' }}{{ $product['price_per_unit'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="text-align:center;">No products found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> connects rental companies to help maximize equipment
                    utilization and simplify collaboration within the rental industry.
                </p>
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
