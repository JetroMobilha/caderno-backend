<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'ocr' => [
        'tesseract_path' => env('OCR_TESSERACT_PATH', 'tesseract'),
        'node_path' => env('OCR_NODE_PATH', 'node'),
        'tessdata_dir' => env('OCR_TESSDATA_DIR'),
        'queue_name' => env('OCR_QUEUE_NAME', 'ocr'),
        'queue_tries' => (int) env('OCR_QUEUE_TRIES', 3),
        'queue_timeout' => (int) env('OCR_QUEUE_TIMEOUT', 600),
        'queue_workers' => (int) env('OCR_QUEUE_WORKERS', 2),
    ],

];
