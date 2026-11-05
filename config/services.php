<?php

declare(strict_types=1);

return [

    'freelancer' => [
        'base_url' => env('FREELANCER_API_URL', 'https://www.freelancer.com/api'),
        'oauth_token' => env('FREELANCER_OAUTH_TOKEN'),
        'page_size' => (int) env('FREELANCER_PAGE_SIZE', 100),
        'timeout' => (int) env('FREELANCER_TIMEOUT', 30),
        'request_delay_ms' => (int) env('FREELANCER_REQUEST_DELAY_MS', 250),
    ],

];
