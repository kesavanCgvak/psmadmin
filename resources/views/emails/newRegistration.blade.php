<!DOCTYPE html
    PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>New Registration</title>
    <style type="text/css">
        body {
            margin: 0;
            padding: 0;
            background-color: #f6f6f6;
            font-family: Arial, sans-serif;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
        }

        .header {
            background-color: #ffffff;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #eeeeee;
        }

        .header h1 {
            color: #333333;
            font-size: 24px;
            margin: 15px 0 0 0;
        }

        .content {
            padding: 30px;
            color: #555555;
            font-size: 16px;
            line-height: 24px;
        }

        .content p {
            margin: 8px 0;
        }

        .footer {
            background-color: #ee4c50;
            color: #ffffff;
            font-size: 14px;
            text-align: center;
            padding: 20px;
        }
    </style>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0"
        style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.08);">
        <!-- Header with Logo -->
        <tr style="background-color: #726d6c;">
            <td style="text-align: center; padding: 20px;">
                <img src="{{ asset('images/logo-white.png') }}" alt="prosubmarket.com"
                    style="max-width: 200px; height: auto; display: block; margin: 0 auto;">
            </td>
        </tr>
        <tr>
            <td class="content">
                <p><b>Company Name:</b> {{ $company_name }}</p>
                <p><b>User Name:</b> {{ $username }}</p>
                <p><b>User Email:</b> {{ $email }}</p>
                <p><b>Region:</b> {{ $region_name }}</p>
                <p><b>Country:</b> {{ $country_name }}</p>
                <p><b>State/Province:</b> {{ $state_name ?? 'N/A' }}</p>
                <p><b>City:</b> {{ $city_name }}</p>
                <p><b>Contact Phone:</b> {{ $mobile }}</p>

                <p style="margin-top:20px;">Best regards,<br>
                    <strong>Pro Subrental Marketplace Team</strong>
                </p>
            </td>
        </tr>
        <!-- Footer -->
        <tr>
            <td style="background-color:#726d6c; padding: 18px; text-align:center; color:#ffffff; font-size: 13px;">
                &copy; 2025 Pro Subrental Marketplace. All rights reserved.
            </td>
        </tr>
    </table>
</body>

</html>
