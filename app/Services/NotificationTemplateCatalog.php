<?php

namespace App\Services;

use InvalidArgumentException;

final class NotificationTemplateCatalog
{
    private const TEMPLATES = [
        'otpRedacted', 'submissionCitizen', 'newSubmissionAdmin', 'newSubmissionField',
        'manualReviewAdmin', 'decisionVerifiedCitizen',
        'decisionRejectedCitizen', 'decisionPendingCitizen',
        'decisionField', 'fieldFollowUp', 'confirmation', 'userCreated', 'userStatus',
        'roleAssigned', 'roleRemoved', 'roleUpdated', 'ownershipChanged',
        'passwordReset', 'passwordChanged', 'fieldMode', 'adminDigest',
        'fieldDigest',
    ];

    /** @return array{subject:string,body:string} */
    public function render(string $templateKey, string $locale, array $arguments): array
    {
        if (! in_array($templateKey, self::TEMPLATES, true)) {
            throw new InvalidArgumentException('Unknown notification template.');
        }

        $locale = in_array($locale, ['fr', 'ht'], true) ? $locale : 'ht';
        $subject = lang('Notifications.' . $templateKey . 'Subject', $arguments, $locale);
        $body = lang('Notifications.' . $templateKey . 'Body', $arguments, $locale);

        if (! is_string($subject) || trim($subject) === '' || ! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException('Notification template is incomplete.');
        }

        return [
            'subject' => mb_substr(trim($subject), 0, 250),
            'body' => mb_substr(trim($body), 0, 4000),
        ];
    }
}
