<?php

return [
    'account_email' => env('GMAIL_ACCOUNT_EMAIL'),

    // Generated at https://myaccount.google.com/apppasswords — requires 2FA
    // enabled on the account. NOT your normal Gmail password. Treat this
    // with the same care as a real credential: local .env only, never commit.
    'app_password' => env('GMAIL_APP_PASSWORD'),

    'imap_host' => env('GMAIL_IMAP_HOST', 'imap.gmail.com'),
    'imap_port' => env('GMAIL_IMAP_PORT', 993),

    'smtp_host' => env('GMAIL_SMTP_HOST', 'smtp.gmail.com'),
    'smtp_port' => env('GMAIL_SMTP_PORT', 465),

    // Cron expression controlling poll frequency. Default: every 5 minutes.
    'poll_cron' => env('GMAIL_POLL_CRON', '*/5 * * * *'),

    // On first run (no stored cursor yet), fetch messages received in the
    // last N days rather than the entire mailbox history.
    'initial_fetch_days' => env('GMAIL_INITIAL_FETCH_DAYS', 3),
];
