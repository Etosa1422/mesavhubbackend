<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Low Balance Alert</title>
</head>
<body style="margin:0;padding:0;background:#f0fdf4;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(22,163,74,0.1);">

          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#15803d,#16a34a,#22c55e);padding:36px 40px;text-align:center;">
              <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:800;letter-spacing:-0.5px;">{{ config('app.name') }}</h1>
              <p style="margin:6px 0 0;color:rgba(255,255,255,0.85);font-size:13px;">⚠️ Low Balance Alert</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:40px;">
              <p style="margin:0 0 8px;font-size:16px;color:#14532d;font-weight:700;">
                Hi {{ $user->first_name ?? $user->username }},
              </p>
              <p style="margin:0 0 28px;font-size:14px;color:#374151;line-height:1.7;">
                Your wallet balance is running low. Top up now to ensure uninterrupted access to our services.
              </p>

              <!-- Balance Box -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
                <tr>
                  <td align="center" style="background:#fef9c3;border:2px solid #fde047;border-radius:10px;padding:24px;">
                    <p style="margin:0;color:#a16207;font-size:13px;font-weight:600;">Current Balance</p>
                    <p style="margin:6px 0 0;color:#92400e;font-size:36px;font-weight:800;">
                      {{ $user->currency ?? '' }} {{ number_format($balance, 2) }}
                    </p>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 24px;font-size:14px;color:#374151;line-height:1.7;text-align:center;">
                Fund your wallet quickly to keep placing orders without interruption.
              </p>

              <!-- CTA -->
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding:0 0 28px;">
                    <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard/add-funds"
                       style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:50px;font-size:14px;font-weight:700;">
                      Top Up Now
                    </a>
                  </td>
                </tr>
              </table>

              <hr style="border:none;border-top:1px solid #bbf7d0;margin:0 0 24px;" />

              <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;">
                You're receiving this because your balance dropped below our low-balance threshold. You can manage notification preferences in your account settings.<br /><br />
                — The {{ config('app.name') }} Team
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f0fdf4;padding:20px 40px;text-align:center;border-top:1px solid #bbf7d0;">
              <p style="margin:0;font-size:12px;color:#4ade80;">&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
