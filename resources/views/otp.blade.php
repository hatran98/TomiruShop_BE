<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>OTP Codes</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            height: 100vh;
        }

        h1 {
            margin: 0 auto;
            font-weight: 900;
        }
        .info {
            padding: 10px;
            background-color: rgb(149, 149, 248);
            position: relative;
            border: 1px solid rgb(94, 94, 206);
        }
        img {
            justify-self: start;
            position: absolute;
        }
        .header {
            text-align: right;
            justify-items: flex-end;
            display: flex;
            align-items: center;
        }
        .container {
            width: max-content;
            margin: 0 auto;
            justify-content: center;
        }

        .side-info {
            margin: 0 auto;
            text-align: end;
        }
        .otp-container {
            border: 1px solid rgb(94, 94, 206);
            width: max-content; /* Đảm bảo container không bị co lại */
        }
        .otp-table {
            width: 100%;
            border-collapse: collapse;
        }
        .otp-row {
            display: table-row;
        }
        .otp-cell {
            display: table-cell;
            border: 1px solid rgb(94, 94, 206);
            padding: 10px;
            text-align: center;
            font-weight: bold;
        }
        .stt {
            width: 100%;
            display: block;
            text-align: center;
            color: red; /* Màu đỏ cho STT */
        }
        .token {
            color: blue; /* Màu xanh cho Token */
        }
    </style>
</head>
<body>
<div class="container">
    <div class="info">
        <img
            src="https://app.tomiru.com/assets/logo-light-944780b7.png"
            alt="tomiru"
            width="100"
        />
        <div class="header">
            <h1>OTP Card</h1>
        </div>
        <div class="side-info">
            <div>Tên khách hàng : <strong><?php echo e($user_name); ?></strong></div>
            <div>Mã thẻ : <strong><?php echo e($card_serial); ?></strong></div>
        </div>

    </div>

    <div class="otp-container">
        <table class="otp-table">
            <tbody>
            <?php // Start of Blade syntax ?>
            <?php for($i = 0; $i < 5; $i++): ?>
            <tr class="otp-row">
                    <?php for($j = 0; $j < 7; $j++): ?>
                    <?php
                    $index = $i * 7 + $j + 1;
                    $otp = $otpData[$index - 1];
                    ?>
                <td class="otp-cell">
                    <span class="stt"><?php echo e($index); ?></span>  <span class="token"><?php echo e($otp->token); ?></span>
                </td>
                <?php endfor; ?>
            </tr>
            <?php endfor; ?>
            <?php // End of Blade syntax ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/pixer-api63/resources/views/otp.blade.php ENDPATH**/ ?>
