<?php

$errorCode = 400;
$titleKey = 'ErrorPage.badRequestTitle';
$messageKey = 'ErrorPage.badRequestMessage';
$technicalMessage = $message ?? null;
$allowRetry = true;

require __DIR__ . DIRECTORY_SEPARATOR . '_localized_page.php';
