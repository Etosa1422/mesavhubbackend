<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Order Status Update</title>
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
              <p style="margin:6px 0 0;color:rgba(255,255,255,0.85);font-size:13px;">Order Status Update</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:40px;">
              <p style="margin:0 0 8px;font-size:16px;color:#14532d;font-weight:700;">
                Hi {{ $user->first_name ?? $user->username }},
              </p>

              @php
                $status = strtolower($order->status);
                $isCompleted = $status === 'completed';
                $isCancelled = in_array($status, ['cancelled', 'canceled']);
                $isRefunded  = $status === 'refunded';
                $badgeBg     = $isCompleted ? '#dcfce7' : ($isCancelled || $isRefunded ? '#fee2e2' : '#fef9c3');
                $badgeColor  = $isCompleted ? '#15803d' : ($isCancelled || $isRefunded ? '#b91c1c' : '#a16207');
                $statusLabel = ucfirst($order->status);
              @endphp

              <p style="margin:0 0 28px;font-size:14px;color:#374151;line-height:1.7;">
                @if($isCompleted)
                  Great news! Your order has been completed. Here's the summary:
                @elseif($isCancelled)
                  Your order has been cancelled. {{ $order->reason ? 'Reason: ' . $order->reason : 'Please contact support if you have any questions.' }}
                @elseif($isRefunded)
                  Your order has been refunded. The amount has been credited back to your wallet.
                @else
                  Your order status has been updated. Here's the current summary:
                @endif
              </p>

              <!-- Order Details -->
              <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border-left:4px solid #16a34a;border-radius:8px;margin:0 0 28px;">
                <tr>
                  <td style="padding:20px;">
                    <table width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;width:140px;">Order ID</td>
                        <td style="padding:6px 0;font-size:13px;color:#14532d;font-weight:700;">#{{ $order->id }}</td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Service</td>
                        <td style="padding:6px 0;font-size:13px;color:#14532d;">{{ $order->service->service_title ?? 'N/A' }}</td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Quantity</td>
                        <td style="padding:6px 0;font-size:13px;color:#14532d;">{{ number_format($order->quantity) }}</td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Amount</td>
                        <td style="padding:6px 0;font-size:13px;color:#14532d;">{{ $user->currency ?? '' }} {{ number_format($order->price, 2) }}</td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Status</td>
                        <td style="padding:6px 0;">
                          <span style="display:inline-block;background:{{ $badgeBg }};color:{{ $badgeColor }};font-size:12px;font-weight:700;padding:3px 10px;border-radius:50px;">
                            {{ $statusLabel }}
                          </span>
                        </td>
                      </tr>
                      @if($order->status_description)
                      <tr>
                        <td style="padding:6px 0;font-size:13px;color:#166534;font-weight:600;">Note</td>
                        <td style="padding:6px 0;font-size:13px;color:#374151;">{{ $order->status_description }}</td>
                      </tr>
                      @endif
                    </table>
                  </td>
                </tr>
              </table>

              <!-- CTA -->
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding:0 0 28px;">
                    <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard/orders"
                       style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:50px;font-size:14px;font-weight:700;">
                      View My Orders
                    </a>
                  </td>
                </tr>
              </table>

              <hr style="border:none;border-top:1px solid #bbf7d0;margin:0 0 24px;" />

              <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;">
                If you have any questions about this order, please open a support ticket.<br /><br />
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
