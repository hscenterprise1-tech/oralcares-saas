<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OralCares</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="background:#0a2540;padding:20px 28px;">
                            <span style="color:#ffffff;font-size:18px;font-weight:bold;letter-spacing:-0.2px;">OralCares</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;font-size:15px;line-height:1.6;color:#0f172a;">
                            {{-- Body is sanitised at composition time in CrmEmailService. --}}
                            {!! $bodyHtml !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
                            This message was sent by the OralCares sales team. Reply directly to continue the conversation.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
