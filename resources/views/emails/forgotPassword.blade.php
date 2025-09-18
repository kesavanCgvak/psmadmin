<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Your Password</title>
  <style type="text/css">
    body {
      margin: 0;
      padding: 0;
      background-color: #f6f6f6;
      font-family: Arial, sans-serif;
    }

    table {
      border-collapse: collapse;
    }

    td {
      padding: 0;
    }

    .btn {
      display: inline-block;
      padding: 12px 24px;
      margin-top: 20px;
      background-color: #e8d50b;
      color: #000 !important;
      font-size: 16px;
      font-weight: bold;
      text-decoration: none;
      border-radius: 6px;
    }
  </style>
</head>

<body style="margin: 0; padding: 0; background-color: #f6f6f6; font-family: Arial, sans-serif;">
  <table align="center" border="0" cellpadding="0" cellspacing="0" width="600">
    <tr>
      <td align="center" bgcolor="#ffffff">
        <h1 style="color: #333333;">Reset Your Password</h1>
      </td>
    </tr>
    <tr>
      <td bgcolor="#ffffff" style="padding: 20px 30px 40px 30px; text-align: left;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%">
          <tr>
            <td style="color: #555555; font-size: 16px; line-height: 24px;">
              <p><b>Hi {{ $full_name }},</b></p>
              <p>We received a request to reset your password.</p>
              <p>If you did not make this request, you can safely ignore this email.</p>
              <p>Otherwise, please click the button below to reset your password:</p>
              <p style="text-align:center;">
                <a href="{{ env('APP_FRONTEND_URL') }}/reset-password?token={{ $token }}" class="btn">Reset Password</a>
              </p>
              <p>If the button above doesn’t work, copy and paste this link into your browser:</p>
              <p style="word-break: break-all; font-size: 14px; color: #0066cc;">
                {{ env('APP_FRONTEND_URL') }}#/reset-password/{{ $token }}
              </p>
              <p>Best regards,<br>Pro Subrental Marketplace</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td bgcolor="#726d6c" style="padding: 30px 30px 30px 30px;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%">
          <tr>
            <td align="center" style="color: #ffffff; font-size: 14px;">
              &copy; 2025 Pro Subrental Marketplace. All rights reserved.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>

</html>
