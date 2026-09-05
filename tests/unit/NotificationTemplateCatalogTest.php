<?php

namespace Tests\Unit;

use App\Services\NotificationTemplateCatalog;
use CodeIgniter\Test\CIUnitTestCase;

final class NotificationTemplateCatalogTest extends CIUnitTestCase
{
    private const TEMPLATE_ARGUMENTS = [
        'otpRedacted' => ['purpose'],
        'submissionCitizen' => ['name', 'DOS-TEST', 'https://example.test/follow'],
        'newSubmissionAdmin' => ['DOS-TEST', 'Nord', 'contact', 'https://example.test/admin'],
        'newSubmissionField' => ['DOS-TEST', 'Nord', 'https://example.test/admin'],
        'manualReviewAdmin' => ['DOS-TEST', 'https://example.test/admin'],
        'decisionVerifiedCitizen' => ['name', 'DOS-TEST', 'https://example.test/follow'],
        'decisionRejectedCitizen' => ['name', 'DOS-TEST', 'reason', 'https://example.test/follow'],
        'decisionPendingCitizen' => ['name', 'DOS-TEST', 'https://example.test/follow'],
        'decisionField' => ['DOS-TEST', 'Nord', 'status', 'https://example.test/admin'],
        'fieldFollowUp' => ['DOS-TEST', 'Nord', 'https://example.test/admin'],
        'confirmation' => ['DOS-TEST', 'https://example.test/follow'],
        'userCreated' => ['name', 'portal', 'https://example.test/admin'],
        'userStatus' => ['name', 'portal', 'status'],
        'roleAssigned' => ['name', 'portal', 'role'],
        'roleRemoved' => ['name', 'portal', 'role'],
        'roleUpdated' => ['name', 'portal', 'role'],
        'ownershipChanged' => ['name', 'portal', 'status'],
        'passwordReset' => ['name', 'https://example.test/reset'],
        'passwordChanged' => ['name', 'portal'],
        'fieldMode' => ['name', 'status', 'Nord'],
        'adminDigest' => ['2026-09-05', 1, 2, 3, 4, 'https://example.test/admin'],
        'fieldDigest' => ['2026-09-05', 'Nord', 1, 2, 'https://example.test/admin'],
    ];

    public function testEveryTemplateRendersCompletelyInBothLanguages(): void
    {
        $catalog = new NotificationTemplateCatalog();

        foreach (['fr', 'ht'] as $locale) {
            foreach (self::TEMPLATE_ARGUMENTS as $template => $arguments) {
                $message = $catalog->render($template, $locale, $arguments);
                $this->assertNotSame('', $message['subject'], $locale . ':' . $template);
                $this->assertNotSame('', $message['body'], $locale . ':' . $template);
                $this->assertDoesNotMatchRegularExpression(
                    '/\{\d+\}/',
                    $message['subject'] . $message['body'],
                    $locale . ':' . $template
                );
            }
        }
    }

    public function testDecisionTemplatesRenderInBothLanguagesWithoutSensitiveIdentityData(): void
    {
        $catalog = new NotificationTemplateCatalog();

        foreach (['fr', 'ht'] as $locale) {
            $message = $catalog->render('decisionRejectedCitizen', $locale, [
                'Marie', 'DOS-ABCD-1234', 'photo floue', 'https://example.test/swiv/DOS-ABCD-1234',
            ]);
            $this->assertStringContainsString('DOS-ABCD-1234', $message['body']);
            $this->assertStringContainsString('DOS-ABCD-1234', $message['subject']);
            $this->assertStringNotContainsString('Marie', $message['subject']);
            $this->assertStringNotContainsString('1234567890', $message['body']);
            $this->assertStringNotContainsString('{0}', $message['body']);
        }
    }

    public function testRoleAndFieldTemplatesRenderInBothLanguages(): void
    {
        $catalog = new NotificationTemplateCatalog();

        foreach (['fr', 'ht'] as $locale) {
            $role = $catalog->render('roleAssigned', $locale, ['Jean', 'Portail test', 'Agent terrain']);
            $field = $catalog->render('fieldDigest', $locale, [
                '2026-09-05', 'Nord', 12, 3, 'https://example.test/admin/identites',
            ]);
            $decision = $catalog->render('decisionField', $locale, [
                'DOS-ABCD-1234', 'Nord', 'verified', 'https://example.test/admin/identites/test',
            ]);

            $this->assertStringContainsString('Agent terrain', $role['body']);
            $this->assertStringContainsString('Nord', $field['body']);
            $this->assertStringContainsString('12', $field['body']);
            $this->assertStringContainsString('DOS-ABCD-1234', $decision['body']);
        }
    }
}
