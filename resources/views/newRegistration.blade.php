<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>verify your email</title>
  <style type="text/css">
    /* Basic styling for email clients */
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
      padding: 0px;
     
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f6f6f6; font-family: Arial, sans-serif;">
  <table align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="border-collapse: collapse;">
    <tr>
      <td align="center" bgcolor="#ffffff">
        <img src="{{public}}" />
        <h1 style="color: #333333;">New registration</h1>
      </td>
    </tr>
    <tr>
      <td bgcolor="#ffffff" style="padding: 20px 30px 40px 30px;  text-align: left;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%">
          <tr>
            <td style="color: #555555; font-size: 16px; line-height: 24px;">
            <p><b> Company Name:</b> {{ $company_name }}</p>
            <p><b> User Name:</b>  {{ $username }}</p>
            <p><b> User Email:</b>  {{ $email }}</p>
            <p><b> Region:</b>  {{ $region_id }}</p>
            <p><b> Country:</b> {{ $country_id }}</p>
            <p><b> City:</b>  {{ $city_id }}</p>
            <p><b> Contact Phone:</b>  {{ $mobile }}</p>
            <p>Best regards,<br>Pro Sub market</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td bgcolor="#ee4c50" style="padding: 30px 30px 30px 30px;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%">
          <tr>
            <td align="center" style="color: #ffffff; font-size: 14px;">
              &copy; 2025 Your Company. All rights reserved.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>