<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Quote Request</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; color: #333;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: auto; background-color: #ffffff; border-radius: 6px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,0.1);">
    
    <!-- Header with Logo -->
    <tr style="background-color: #000000;">
      <td style="text-align: center; padding: 20px;">
        <img src="https://www.secondwarehouse.com/app/assets/images/logo-header.png" alt="secondwarehouse.com" style="max-width: 200px;">
      </td>
    </tr>

    <!-- Body Content -->
    <tr>
      <td style="padding: 20px;">
        <h2 style="color: #1a73e8;">New Quote Request</h2>
        <p><strong>[renterCompanyName, fallback=renterCompanyName]</strong> is requesting a quote for equipment sourced at Pro Subrental Marketplace.</p>
        <p>
          <strong>Email:</strong> [renterEamilAddress]<br>
          <strong>Phone:</strong> [renterPhoneNumber]
        </p>

        <h3>Rental Details</h3>
        <p>
          <strong>Rental Name:</strong> [rentalName] <br>
          <strong>Rental Dates:</strong> [rentalDate] <br>
          <strong>Delivery Address:</strong> [deliveryAddress]
        </p>

        <h3>Offer</h3>
        <p>[offeredPrice]</p>

        <h3>Private Message</h3>
        <p>[privateMessage]</p>

        <h3>Equipment Requested</h3>
        <table width="100%" border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; text-align: left;">
          <thead style="background-color: #f0f0f0;">
            <tr>
              <th>PSM Code</th>
              <th>Qty</th>
              <th>Equipment</th>
              <th>Rental Software Code</th>
            </tr>
          </thead>
          <tbody>
            [itemRows]
          </tbody>
        </table>

        <p style="margin-top: 20px;">Good luck with your rental. Remember that the sooner you reply to <strong>[renterCompanyName, fallback=renterCompanyName]</strong>'s request, the more likely you will get the rental.</p>

        <p style="font-size: 14px; color: #666;">
          Our job at Pro Subrental Marketplace is to bring you opportunities to maximize the investments you have made in your gear and introduce you to people with whom you can build new relationships.
        </p>
      </td>
    </tr>

    <!-- Footer -->
    <tr style="background-color: #000000;">
      <td style="text-align: center; padding: 20px;">
        <img src="https://www.secondwarehouse.com/app/assets/images/footer-logo.png" alt="Second Warehouse Footer Logo" style="max-width: 290px;margin-bottom: 10px;">
        <div style="color: #FFD700;font-size: 13px;font-weight: bold;">Your Rental Equipment Network</div>
      </td>
    </tr>
  </table>
</body>
</html>
