<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your OTP Code</title>
</head>
<body>
    <p>Your one-time password (OTP) is:</p>
    <h2>{{ $otpCode }}</h2>
    <p>This OTP will expire in {{ $expiresInMinutes }} minutes.</p>
</body>
</html>
