<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $institute }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f4f7;font-family:Georgia,'Times New Roman',serif;color:#1f2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;background:#ffffff;border:1px solid #d9dee5;">
    <tr>
        <td style="background:#111c45;padding:18px 24px;color:#ffffff;">
            <div style="font-size:17px;font-weight:bold;line-height:1.3;">{{ $institute }}</div>
            <div style="font-size:12px;opacity:.85;margin-top:2px;">{{ $address }}</div>
        </td>
    </tr>
    <tr>
        <td style="padding:24px;font-size:15px;line-height:1.6;font-family:Arial,Helvetica,sans-serif;">
            {!! $body !!}
        </td>
    </tr>
    <tr>
        <td style="padding:14px 24px;border-top:1px solid #e4e7eb;font-size:11px;color:#6b7280;font-family:Arial,Helvetica,sans-serif;">
            This is an automated message from the Non-Teaching Recruitment Portal of {{ $institute }}. Please do not reply to this email.
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
