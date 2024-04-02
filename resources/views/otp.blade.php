<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Cards</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        .otp-card {
            width: 300px;
            height: 200px;
            border: 1px solid #ccc;
            border-radius: 10px;
            margin: 20px;
            display: inline-block;
            position: relative;
            background: #f9f9f9;
        }
        .otp-number {
            font-size: 24px;
            font-weight: bold;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        .otp-label {
            font-size: 12px;
            position: absolute;
            bottom: 10px;
            left: 10px;
        }
        .otp-index {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 16px;
            font-weight: bold;
        }
    </style>
</head>
<body>
@foreach($otpData as $otp)
    <div class="otp-card">
        <div class="otp-number">{{ $otp -> token }}</div>
        <div class="otp-label">OTP</div>
        <div class="otp-index">{{ $otp->stt }}</div>
    </div>
@endforeach
</body>
</html>
