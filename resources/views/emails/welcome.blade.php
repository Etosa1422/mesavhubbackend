<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {{ config('app.name') }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Fredoka', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-weight: 350;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f0fdf4;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(22, 163, 74, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #15803d 0%, #16a34a 50%, #22c55e 100%);
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0 0 8px;
            font-size: 28px;
            font-weight: 700;
        }
        .email-header p {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
        }
        .email-body {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            color: #14532d;
            margin-bottom: 16px;
        }
        .info-box {
            background-color: #f0fdf4;
            border-left: 4px solid #16a34a;
            padding: 20px;
            margin: 25px 0;
            border-radius: 8px;
        }
        .info-box p {
            margin: 0;
            color: #166534;
        }
        .features {
            margin: 25px 0;
        }
        .feature-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .feature-icon {
            font-size: 20px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .feature-text strong {
            display: block;
            color: #14532d;
            margin-bottom: 2px;
        }
        .feature-text span {
            color: #166534;
            font-size: 14px;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #15803d 0%, #16a34a 100%);
            color: #ffffff !important;
            padding: 14px 32px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 25px 0;
        }
        .cta-section {
            text-align: center;
            margin: 30px 0;
        }
        .divider {
            border: none;
            border-top: 1px solid #bbf7d0;
            margin: 30px 0;
        }
        .email-footer {
            background-color: #f0fdf4;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #bbf7d0;
        }
        .email-footer p {
            color: #4ade80;
            font-size: 13px;
            margin: 4px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>Welcome to {{ config('app.name') }}! 🎉</h1>
            <p>Your account has been created successfully</p>
        </div>

        <div class="email-body">
            <p class="greeting">Hi {{ $user->first_name ?? $user->username }},</p>

            <p>Welcome aboard! We're excited to have you. Your account is ready and you can start using all our services right away.</p>

            <div class="info-box">
                <p><strong>Your account details:</strong></p>
                <p style="margin-top: 8px;">Username: <strong>{{ $user->username }}</strong></p>
                <p>Email: <strong>{{ $user->email }}</strong></p>
            </div>

            <div class="features">
                <div class="feature-item">
                    <div class="feature-icon">⚡</div>
                    <div class="feature-text">
                        <strong>Instant Services</strong>
                        <span>Access a wide range of SMM and digital services instantly.</span>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">💳</div>
                    <div class="feature-text">
                        <strong>Easy Top-Up</strong>
                        <span>Fund your wallet and start placing orders in minutes.</span>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">🎧</div>
                    <div class="feature-text">
                        <strong>24/7 Support</strong>
                        <span>Our support team is always here to help you.</span>
                    </div>
                </div>
            </div>

            <div class="cta-section">
                <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard" class="cta-button">
                    Go to Dashboard
                </a>
            </div>

            <hr class="divider">

            <p style="color: #64748b; font-size: 14px;">
                If you did not create this account, please ignore this email or contact our support team immediately.
            </p>
        </div>

        <div class="email-footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            <p>This is an automated message, please do not reply directly to this email.</p>
        </div>
    </div>
</body>
</html>
