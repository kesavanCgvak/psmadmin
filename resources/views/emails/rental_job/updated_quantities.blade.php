<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Quantity Updated - Pro Subrental Marketplace</title>
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

                <p style="margin-top: 0;">
                    Hi <strong>{{ $receiver->contact_name }}</strong>,
                </p>

                <p>
                    The requester <strong>{{ $rentalJob->user->company->name ?? 'the user' }}</strong> has updated the
                    product quantities for the rental job <strong>{{ $rentalJob->name }}</strong>.
                </p>

                <p>
                    Please review the updated quantities below. These changes affect your supply job
                    <strong>{{ $supplyJob->name }}</strong>.
                </p>

                <!-- Products Section -->
                <h3 style="color:#1a73e8; margin-top: 30px;">Product Details</h3>

                <table width="100%" cellpadding="8" cellspacing="0"
                    style="border-collapse: collapse; margin-top: 10px;">
                    <thead>
                        <tr style="background:#e8f0fe; text-align:left;">
                            <th style="border-bottom:1px solid #ccc;">PSM Code</th>
                            <th style="border-bottom:1px solid #ccc;">Model</th>
                            <th style="border-bottom:1px solid #ccc;">Old Qty</th>
                            <th style="border-bottom:1px solid #ccc;">New Qty</th>
                            <th style="border-bottom:1px solid #ccc;">Price</th>
                            <th style="border-bottom:1px solid #ccc;">Total</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($updatedProducts as $p)
                        <tr>
                            <td>{{ $p['psm_code'] }}</td>
                            <td>{{ $p['model'] }}</td>
                            <td>{{ $p['old_qty'] }}</td>
                            <td>{{ $p['new_qty'] }}</td>
                            <td>{{ $currency ?? '' }}{{ number_format($p['price'], 2) }}</td>
                            <td>{{ $currency ?? '' }}{{ number_format($p['total'], 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" style="text-align:center;">No products found.</td>
                        </tr>
                        @endforelse

                        <!-- Grand total row -->
                        <tr style="background-color: #f9f9f9; border-top: 2px solid #ddd; font-weight: bold;">
                            <td colspan="5" align="right">Total Amount:</td>
                            <td>{{ $currency ?? '' }}{{ number_format($grandTotal, 2) }}</td>
                        </tr>
                    </tbody>
                </table>

                <p style="font-size: 13px; color: #666; line-height: 1.6; margin-top: 20px;">
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
