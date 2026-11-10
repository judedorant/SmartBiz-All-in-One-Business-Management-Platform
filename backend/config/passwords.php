<?php

return [
    // Recovery mail must never fall back to a transport that logs message bodies.
    'mailer' => env('PASSWORD_MAILER', 'smtp'),
];
