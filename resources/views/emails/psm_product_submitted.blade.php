<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>New PSM Product Submitted for Review</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 25px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                @if($logo_url)
                    <img src="{{ $logo_url }}" alt="Pro Subrental Marketplace"
                        style="max-width: 200px; height: auto;">
                @else
                    <strong style="color: #ffffff;">Pro Subrental Marketplace</strong>
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding: 25px;">
                <h2 style="color: #1a73e8; margin-top: 0;">New PSM Product Submitted for Review</h2>
                <p>A user submitted a product for inclusion in the central PSM inventory. It has <strong>not</strong> been added to inventory yet.</p>

                <table width="100%" cellpadding="8" cellspacing="0"
                    style="background: #f1f5fb; border-radius: 8px; margin-top: 20px; border-collapse: collapse;">
                    <tr>
                        <td style="font-weight: bold; width: 40%;">Product Name</td>
                        <td>{{ $product_name }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Description</td>
                        <td>{{ $description }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Submitted By</td>
                        <td>{{ $submitter_name }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Submitter Email</td>
                        <td>{{ $submitter_email }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Company</td>
                        <td>{{ $company_name }}</td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold;">Submitted</td>
                        <td>{{ $submitted_at }}</td>
                    </tr>
                </table>

                @if($review_url)
                    <p style="margin-top: 24px;">
                        <a href="{{ $review_url }}"
                           style="background-color: #1a73e8; color: #ffffff; padding: 10px 18px; text-decoration: none; border-radius: 4px; display: inline-block;">
                            Review submission
                        </a>
                    </p>
                @endif
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
