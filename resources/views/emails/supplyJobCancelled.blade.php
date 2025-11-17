<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Supply Job Cancelled - Pro Subrental Marketplace</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 25px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 700px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="Pro Subrental Marketplace"
                    style="max-width: 200px; height: auto;">
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8;">Supply Job Cancelled</h2>
                <p>Dear Requester,</p>

                <p>
                    The supplier <strong>{{ $provider ?? '-' }}</strong> has cancelled their participation in your
                    rental request
                    <strong>{{ $supply_job_name ?? '-' }}</strong>.
                </p>

                @if(!empty($reason))
                    <p><strong>Reason:</strong> {{ $reason }}</p>
                @endif

                <p><strong>Status:</strong> {{ $status ?? 'Cancelled' }}</p>
                <p><strong>Date:</strong> {{ $date ?? now()->format('d M Y, h:i A') }}</p>

                @if(!empty($products))
                    <h3 style="color: #1a73e8; margin-top: 25px;">Cancelled Equipment Details</h3>
                    <table width="100%" cellpadding="8" cellspacing="0"
                        style="border-collapse: collapse; margin-top: 10px; font-size: 14px;">
                        <thead style="background-color: #f0f0f0; border-bottom: 2px solid #ddd;">
                            <tr>
                                <th align="left">PSM Code</th>
                                <th align="left">Model</th>
                                <th align="left">Software Code</th>
                                <th align="left">Qty</th>
                                <th align="left">Price</th>
                                <th align="left">Total Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $grandTotal = 0; @endphp

                            @foreach($products as $product)
                                @php
                                    $qty = $product['quantity'] ?? 0;
                                    $price = $product['price'] ?? 0;
                                    $total = $qty * $price;
                                    $grandTotal += $total;
                                @endphp
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td>{{ $product['psm_code'] ?? '—' }}</td>
                                    <td>{{ $product['model'] ?? '-' }}</td>
                                    <td>{{ $product['software_code'] ?? '—' }}</td>
                                    <td>{{ $qty }}</td>
                                    <td>{{ $currency ?? '' }}{{ number_format($price, 2) }}</td>
                                    <td>{{ $currency ?? '' }}{{ number_format($total, 2) }}</td>
                                </tr>
                            @endforeach

                            <tr style="font-weight: bold; background-color: #fafafa;">
                                <td colspan="5" align="right">Grand Total</td>
                                <td>{{ $currency ?? '' }}{{ number_format($grandTotal, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                @endif

                <p style="margin-top: 25px;">You can still send offers to fulfill the remaining requirement.</p>

                <p style="margin-top: 30px;">Regards,<br>
                    <strong>Pro Subrental Marketplace Team</strong>
                </p>

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
