<?php

$counter = getenv('SLATE_REDIRECT_COUNTER');
$privateUrl = getenv('SLATE_REDIRECT_PRIVATE_URL');

if (! is_string($counter) || $counter === '' || ! is_string($privateUrl) || $privateUrl === '') {
    http_response_code(500);

    return;
}

file_put_contents($counter, "request\n", FILE_APPEND | LOCK_EX);
header('Location: '.$privateUrl, true, 302);
