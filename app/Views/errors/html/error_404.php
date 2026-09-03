<?php

$errorCode = 404;
$titleKey = 'ErrorPage.notFoundTitle';
$messageKey = 'ErrorPage.notFoundMessage';
$technicalMessage = $message ?? null;
$allowRetry = false;

require __DIR__ . DIRECTORY_SEPARATOR . '_localized_page.php';
