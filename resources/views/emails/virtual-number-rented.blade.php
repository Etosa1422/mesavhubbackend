<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Virtual Number Rented</title>
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
              <p style="margin:6px 0 0;color:rgba(255,255,255,0.85);font-size:13px;">Virtual Number Rented</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:40px;">
              <p style="margin:0 0 8px;font-size:16px;color:#14532d;font-weight:700;">
                Hi {{ $user->first_name ?? $user->username }},
              </p>
              <p style="margin:0 0 28px;font-size:14px;color:#374151;line-height:1.7;">
                Your virtual number has been assigned. Use it to receive your verification code. <strong>It will expire in 10 minutes</strong>, so act quickly!
              </p>

              <!-- Number Highlight -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                <tr>
                  <td align="center" style="background:linear-gradient(135deg,#15803d,#16a34a);border-radius:10px;padding:24px;">
                    <p style="margin:0;color:rgba(255,255,255,0.8);font-size:13px;">Your Virtual Number</p>
                    <p style="margin:8px 0 0;color:#ffffff;font-size:28px;font-weight:800;letter-spacing:2px;">
                      {{ $rental->country_flag ?? '' }} {{ $rental->phone_number }}
                    </p>
                  </td>
                </tr>
              </table>

              <!-- Rental Details -->
              <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border-left:4px solid #16a34a;border-radius:8px;margin:0 0 28px;">
                <tr>
                  <td style="padding:20px;">
                    <table width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;width:140px;">Country</td>
                        <td style="padding:6px 0;font-size:13px;color:#14532d;">{{ $rental->country_name ?? $rental->country_code }}</td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Service</td>
                        <td style="padding:6px 0;font-size:13px;color:#14532d;">{{ $rental->service_label ?? $rental->service }}</td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Cost</td>
                        <td style="padding:6px 0;font-size:13px;color:#15803d;font-weight:700;">
                          {{ $user->currency ?? '' }} {{ number_format($rental->price, 2) }}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Expires At</td>
                        <td style="padding:6px 0;font-size:13px;color:#14532d;font-weight:600;">
                          {{ $rental->expires_at ? $rental->expires_at->format('H:i — M d, Y') : 'N/A' }}
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Provider</td>
                        <td style="padding:6px 0;font-size:13px;color:#14532d;">{{ ucwords(str_replace('_', ' ', $rental->provider ?? 'N/A')) }}</td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- CTA -->
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding:0 0 28px;">
                    <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard/virtual-numbers"
                       style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:50px;font-size:14px;font-weight:700;">
                      View My Numbers
                    </a>
                  </td>
                </tr>
              </table>

              <hr style="border:none;border-top:1px solid #bbf7d0;margin:0 0 24px;" />

              <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;">
                This number will expire shortly. If you did not rent this number, please contact our support team immediately.<br /><br />
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
