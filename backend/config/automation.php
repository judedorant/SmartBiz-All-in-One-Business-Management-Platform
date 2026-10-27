<?php

return [
    'webhook_hosts' => array_values(array_filter(array_map('trim', explode(',', env('SMARTBIZ_WEBHOOK_HOSTS', ''))))),
    'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/integrations/google/callback')],
];
