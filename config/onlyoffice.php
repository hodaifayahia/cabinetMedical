<?php

return [
    'url' => rtrim((string) env('ONLYOFFICE_URL', 'http://localhost:8088'), '/'),
    'internal_url' => rtrim((string) env('ONLYOFFICE_INTERNAL_URL', env('ONLYOFFICE_URL', 'http://localhost:8088')), '/'),
    'app_url' => rtrim((string) env('ONLYOFFICE_APP_URL', env('APP_URL', 'http://localhost:8000')), '/'),
    'jwt_secret' => (string) env('ONLYOFFICE_JWT_SECRET', ''),
];
