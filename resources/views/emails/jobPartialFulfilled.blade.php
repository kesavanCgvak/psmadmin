<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Updated Availability - Pro Subrental Marketplace</title>
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

        <tr>
            <td style="padding: 25px;">
                <p>
                    Hello there,<br><br>
                    The rental request <strong>{{ $rental_job_name }}</strong> has been partially fulfilled.
                    Remaining quantity available for offers: <strong>{{ $remaining_quantity }}</strong>.
                </p><br>
                <p>You can still send your offer to fulfill the remaining requirement.</p>
                <p style="font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Pro Subrental Marketplace</strong> connects rental companies to help you maximize
                    equipment utilization and grow your network in the rental industry.
                </p>

                <!-- Products Section -->
                <h3 style="color:#1a73e8; margin-top: 30px;">Product Details</h3>

                <table width="100%" cellpadding="8" cellspacing="0"
                    style="border-collapse: collapse; margin-top: 10px;">
                    <thead>
                        <tr style="background:#e8f0fe; text-align:left;">
                            <th style="border-bottom:1px solid #ccc;">PSM Code</th>
                            <th style="border-bottom:1px solid #ccc;">Model</th>
                            <th style="border-bottom:1px solid #ccc;">Software Code</th>
                            <th style="border-bottom:1px solid #ccc;">Requested Quantity</th>
                            <th style="border-bottom:1px solid #ccc;">Fulfilled Quantity</th>
                            <th style="border-bottom:1px solid #ccc;">Remaining Quantity</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>{{ $product['psm_code'] }}</td>
                                <td>{{ $product['model'] }}</td>
                                <td>{{ $product['software_code'] }}</td>
                                <td>{{ $product['requested_quantity'] }}</td>
                                <td>{{ $product['fulfilled_quantity'] }}</td>
                                <td>{{ $product['remaining_quantity'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="text-align:center;">No products found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
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
