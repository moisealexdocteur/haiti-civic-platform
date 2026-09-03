<?php

namespace App\Services;

use App\Services\Otp\MetaWhatsAppOtpTransport;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpChannelRouter;
use App\Services\Otp\OtpDeliveryRequest;
use App\Services\Otp\OtpTransportInterface;
use App\Services\Otp\SmtpEmailOtpTransport;
use App\Services\Otp\TwilioSmsOtpTransport;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TenantCommunicationSettingsService
{
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private AuditService $audit;
    private TenantSecretCipher $cipher;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?TenantSecretCipher $cipher = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->authorization = new AuthorizationService($tenantContext, $this->db);
        $this->audit = new AuditService($tenantContext, $this->db);
        $this->cipher = $cipher ?? new TenantSecretCipher($tenantContext);
    }

    public function readForActor(int $actorUserId): array
    {
        $this->requirePermission($actorUserId, 'settings.view');
        $row = $this->row();

        return $this->publicValues($row);
    }

    public function saveForActor(int $actorUserId, array $input): array
    {
        $this->requirePermission($actorUserId, 'settings.manage');
        $tenantId = $this->tenantContext->id();

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start settings transaction.');
            }

            $current = $this->db->query(
                'SELECT * FROM `tenant_communication_settings` WHERE `tenant_id` = ? LIMIT 1 FOR UPDATE',
                [$tenantId]
            )->getFirstRow('array');

            $values = $this->normalizedValues($input, $current);
            $now = gmdate('Y-m-d H:i:s');

            foreach (['whatsapp', 'sms', 'email'] as $channel) {
                if ($current === null || $this->channelChanged($channel, $values, $current, $input)) {
                    $values[$channel . '_validation_status'] = 'untested';
                    $values[$channel . '_validated_at'] = null;
                }
            }

            $values['updated_by_user_id'] = $actorUserId;
            $values['updated_at'] = $now;

            if ($current === null) {
                $values['tenant_id'] = $tenantId;
                $values['created_at'] = $now;
                $ok = $this->db->table('tenant_communication_settings')->insert($values);
            } else {
                $ok = $this->db->table('tenant_communication_settings')
                    ->where('tenant_id', $tenantId)
                    ->update($values);
            }

            if (! $ok) {
                throw new RuntimeException('Could not save communication settings.');
            }

            $this->audit->record(
                event: 'settings.communication_updated',
                actorUserId: $actorUserId,
                entityType: 'tenant_communication_settings',
                entityId: $tenantId,
                context: [
                    'whatsapp_enabled' => (bool) $values['whatsapp_enabled'],
                    'sms_enabled' => (bool) $values['sms_enabled'],
                    'email_enabled' => (bool) $values['email_enabled'],
                    'whatsapp_secret_changed' => $this->secretWasChanged($input, 'whatsapp_access_token'),
                    'twilio_secret_changed' => $this->secretWasChanged($input, 'twilio_auth_token'),
                    'smtp_secret_changed' => $this->secretWasChanged($input, 'smtp_password'),
                ]
            );

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit communication settings.');
            }

            return $this->publicValues(array_merge($current ?? [], $values));
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function hasStoredSettings(): bool
    {
        return $this->row() !== null;
    }

    public function testForActor(
        int $actorUserId,
        string $channel,
        array $input,
        string $destination
    ): array {
        $this->requirePermission($actorUserId, 'settings.manage');
        $channel = $this->channel($channel);
        $current = $this->row();
        $testInput = $input;
        $testInput['whatsapp_enabled'] = $channel === 'whatsapp' ? '1' : '0';
        $testInput['sms_enabled'] = $channel === 'sms' ? '1' : '0';
        $testInput['email_enabled'] = $channel === 'email' ? '1' : '0';
        $values = $this->normalizedValues($testInput, $current);

        if (
            $channel === 'email'
            && strtolower((string) ($values['smtp_host'] ?? '')) === 'mail.google.com'
        ) {
            return (new CommunicationChannelDiagnostic())->failure(
                $channel,
                'smtp_gmail_host_invalid',
                'mail.google.com'
            );
        }

        $transport = $this->testTransport($channel, $values);
        $request = $this->testRequest($channel, $destination);
        $result = $transport->deliver($request);

        if (! $result->accepted) {
            $this->audit->record(
                event: 'settings.communication_channel_test_failed',
                actorUserId: $actorUserId,
                entityType: 'tenant_communication_settings',
                entityId: $this->tenantContext->id(),
                context: [
                    'channel' => $channel,
                    'failure_code' => $result->failureCode,
                ]
            );

            return (new CommunicationChannelDiagnostic())->failure(
                $channel,
                $result->failureCode ?? 'provider_rejected',
                $result->providerDetail
            );
        }

        $this->saveValidatedChannel($actorUserId, $channel, $values, $input, $current);

        return (new CommunicationChannelDiagnostic())->success($channel);
    }

    public function deleteForActor(int $actorUserId, string $channel): void
    {
        $this->requirePermission($actorUserId, 'settings.manage');
        $channel = $this->channel($channel);
        $row = $this->row();

        if ($row === null) {
            return;
        }

        $columns = match ($channel) {
            'whatsapp' => [
                'whatsapp_enabled' => 0,
                'whatsapp_graph_version' => null,
                'whatsapp_phone_number_id' => null,
                'whatsapp_access_token_encrypted' => null,
                'whatsapp_template_name' => null,
                'whatsapp_template_language' => null,
            ],
            'sms' => [
                'sms_enabled' => 0,
                'twilio_account_sid' => null,
                'twilio_auth_token_encrypted' => null,
                'twilio_from_number' => null,
                'twilio_messaging_service_sid' => null,
            ],
            default => [
                'email_enabled' => 0,
                'smtp_host' => null,
                'smtp_port' => null,
                'smtp_crypto' => null,
                'smtp_user' => null,
                'smtp_password_encrypted' => null,
                'email_from_address' => null,
                'email_from_name' => null,
            ],
        };

        $columns[$channel . '_validation_status'] = 'untested';
        $columns[$channel . '_validated_at'] = null;
        $columns['updated_by_user_id'] = $actorUserId;
        $columns['updated_at'] = gmdate('Y-m-d H:i:s');

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start channel deletion transaction.');
            }

            if (! $this->db->table('tenant_communication_settings')
                ->where('tenant_id', $this->tenantContext->id())
                ->update($columns)) {
                throw new RuntimeException('Could not delete communication channel.');
            }

            $this->audit->record(
                event: 'settings.communication_channel_deleted',
                actorUserId: $actorUserId,
                entityType: 'tenant_communication_settings',
                entityId: $this->tenantContext->id(),
                context: ['channel' => $channel]
            );

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit channel deletion.');
            }
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function router(): OtpChannelRouter
    {
        $config = $this->runtimeConfiguration();
        $transports = [];

        if ($config['whatsapp'] !== null) {
            $value = $config['whatsapp'];
            $transports[] = new MetaWhatsAppOtpTransport(
                $value['graph_version'],
                $value['phone_number_id'],
                $value['access_token'],
                $value['template_name'],
                $value['template_language']
            );
        }

        if ($config['sms'] !== null) {
            $value = $config['sms'];
            $transports[] = new TwilioSmsOtpTransport(
                $value['account_sid'],
                $value['auth_token'],
                $value['from_number'],
                $value['messaging_service_sid']
            );
        }

        if ($config['email'] !== null) {
            $value = $config['email'];
            $transports[] = new SmtpEmailOtpTransport(
                $value['host'],
                $value['port'],
                $value['crypto'],
                $value['user'],
                $value['password'],
                $value['from_address'],
                $value['from_name']
            );
        }

        return new OtpChannelRouter($transports);
    }

    public function smtpConfiguration(): ?array
    {
        return $this->runtimeConfiguration()['email'];
    }

    private function runtimeConfiguration(): array
    {
        $row = $this->row();

        if ($row === null) {
            return ['whatsapp' => null, 'sms' => null, 'email' => null];
        }

        $whatsapp = null;
        $sms = null;
        $email = null;

        if (
            (int) $row['whatsapp_enabled'] === 1
            && (string) ($row['whatsapp_validation_status'] ?? 'untested') === 'valid'
        ) {
            $whatsapp = [
                'graph_version' => (string) $row['whatsapp_graph_version'],
                'phone_number_id' => (string) $row['whatsapp_phone_number_id'],
                'access_token' => $this->cipher->decrypt(
                    'whatsapp_access_token',
                    (string) $row['whatsapp_access_token_encrypted']
                ),
                'template_name' => (string) $row['whatsapp_template_name'],
                'template_language' => (string) $row['whatsapp_template_language'],
            ];
        }

        if (
            (int) $row['sms_enabled'] === 1
            && (string) ($row['sms_validation_status'] ?? 'untested') === 'valid'
        ) {
            $sms = [
                'account_sid' => (string) $row['twilio_account_sid'],
                'auth_token' => $this->cipher->decrypt(
                    'twilio_auth_token',
                    (string) $row['twilio_auth_token_encrypted']
                ),
                'from_number' => $this->nullable($row['twilio_from_number'] ?? null),
                'messaging_service_sid' => $this->nullable($row['twilio_messaging_service_sid'] ?? null),
            ];
        }

        if (
            (int) $row['email_enabled'] === 1
            && (string) ($row['email_validation_status'] ?? 'untested') === 'valid'
        ) {
            $email = [
                'host' => (string) $row['smtp_host'],
                'port' => (int) $row['smtp_port'],
                'crypto' => (string) $row['smtp_crypto'],
                'user' => (string) $row['smtp_user'],
                'password' => $this->cipher->decrypt(
                    'smtp_password',
                    (string) $row['smtp_password_encrypted']
                ),
                'from_address' => (string) $row['email_from_address'],
                'from_name' => (string) $row['email_from_name'],
            ];
        }

        return ['whatsapp' => $whatsapp, 'sms' => $sms, 'email' => $email];
    }

    private function normalizedValues(array $input, ?array $current): array
    {
        $whatsappEnabled = $this->bool($input, 'whatsapp_enabled');
        $smsEnabled = $this->bool($input, 'sms_enabled');
        $emailEnabled = $this->bool($input, 'email_enabled');

        $values = [
            'whatsapp_enabled' => $whatsappEnabled ? 1 : 0,
            'whatsapp_graph_version' => $this->text($input, 'whatsapp_graph_version', 20),
            'whatsapp_phone_number_id' => $this->text($input, 'whatsapp_phone_number_id', 30),
            'whatsapp_template_name' => $this->text($input, 'whatsapp_template_name', 512),
            'whatsapp_template_language' => $this->text($input, 'whatsapp_template_language', 10),
            'sms_enabled' => $smsEnabled ? 1 : 0,
            'twilio_account_sid' => $this->text($input, 'twilio_account_sid', 40),
            'twilio_from_number' => $this->text($input, 'twilio_from_number', 20),
            'twilio_messaging_service_sid' => $this->text($input, 'twilio_messaging_service_sid', 40),
            'email_enabled' => $emailEnabled ? 1 : 0,
            'smtp_host' => $this->text($input, 'smtp_host', 253),
            'smtp_port' => $this->port($input['smtp_port'] ?? null),
            'smtp_crypto' => strtolower($this->text($input, 'smtp_crypto', 10) ?? ''),
            'smtp_user' => $this->text($input, 'smtp_user', 254),
            'email_from_address' => $this->text($input, 'email_from_address', 254),
            'email_from_name' => $this->text($input, 'email_from_name', 160),
        ];

        $values['whatsapp_access_token_encrypted'] = $this->secretValue(
            $input,
            'whatsapp_access_token',
            'whatsapp_access_token_encrypted',
            $current
        );
        $values['twilio_auth_token_encrypted'] = $this->secretValue(
            $input,
            'twilio_auth_token',
            'twilio_auth_token_encrypted',
            $current
        );
        $values['smtp_password_encrypted'] = $this->secretValue(
            $input,
            'smtp_password',
            'smtp_password_encrypted',
            $current
        );

        if ($whatsappEnabled) {
            new MetaWhatsAppOtpTransport(
                (string) $values['whatsapp_graph_version'],
                (string) $values['whatsapp_phone_number_id'],
                $this->requiredSecret($values, 'whatsapp_access_token_encrypted', 'whatsapp_access_token'),
                (string) $values['whatsapp_template_name'],
                (string) $values['whatsapp_template_language']
            );
        }

        if ($smsEnabled) {
            new TwilioSmsOtpTransport(
                (string) $values['twilio_account_sid'],
                $this->requiredSecret($values, 'twilio_auth_token_encrypted', 'twilio_auth_token'),
                $this->nullable($values['twilio_from_number']),
                $this->nullable($values['twilio_messaging_service_sid'])
            );
        }

        if ($emailEnabled) {
            new SmtpEmailOtpTransport(
                (string) $values['smtp_host'],
                (int) $values['smtp_port'],
                (string) $values['smtp_crypto'],
                (string) $values['smtp_user'],
                $this->requiredSecret($values, 'smtp_password_encrypted', 'smtp_password'),
                (string) $values['email_from_address'],
                (string) $values['email_from_name']
            );
        }

        return $values;
    }

    private function requiredSecret(array $values, string $encryptedKey, string $name): string
    {
        $payload = $values[$encryptedKey] ?? null;

        if (! is_string($payload) || $payload === '') {
            throw new InvalidArgumentException('A required provider secret is missing.');
        }

        return $this->cipher->decrypt($name, $payload);
    }

    private function secretValue(array $input, string $inputKey, string $column, ?array $current): ?string
    {
        if ($this->bool($input, 'clear_' . $inputKey)) {
            return null;
        }

        $value = trim((string) ($input[$inputKey] ?? ''));

        if ($value === '') {
            $existing = $current[$column] ?? null;
            return is_string($existing) && $existing !== '' ? $existing : null;
        }

        if (strlen($value) > 4096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('A provider secret is invalid.');
        }

        return $this->cipher->encrypt($inputKey, $value);
    }

    private function publicValues(?array $row): array
    {
        $row ??= [];

        return [
            'stored' => $row !== [],
            'whatsapp_enabled' => (int) ($row['whatsapp_enabled'] ?? 0) === 1,
            'whatsapp_graph_version' => (string) ($row['whatsapp_graph_version'] ?? 'v26.0'),
            'whatsapp_phone_number_id' => (string) ($row['whatsapp_phone_number_id'] ?? ''),
            'whatsapp_template_name' => (string) ($row['whatsapp_template_name'] ?? ''),
            'whatsapp_template_language' => (string) ($row['whatsapp_template_language'] ?? 'ht'),
            'whatsapp_secret_set' => $this->hasSecret($row, 'whatsapp_access_token_encrypted'),
            'whatsapp_configured' => $this->hasSecret($row, 'whatsapp_access_token_encrypted')
                || (string) ($row['whatsapp_phone_number_id'] ?? '') !== '',
            'whatsapp_validation_status' => (string) ($row['whatsapp_validation_status'] ?? 'untested'),
            'whatsapp_validated_at' => $row['whatsapp_validated_at'] ?? null,
            'sms_enabled' => (int) ($row['sms_enabled'] ?? 0) === 1,
            'twilio_account_sid' => (string) ($row['twilio_account_sid'] ?? ''),
            'twilio_from_number' => (string) ($row['twilio_from_number'] ?? ''),
            'twilio_messaging_service_sid' => (string) ($row['twilio_messaging_service_sid'] ?? ''),
            'twilio_secret_set' => $this->hasSecret($row, 'twilio_auth_token_encrypted'),
            'sms_configured' => $this->hasSecret($row, 'twilio_auth_token_encrypted')
                || (string) ($row['twilio_account_sid'] ?? '') !== '',
            'sms_validation_status' => (string) ($row['sms_validation_status'] ?? 'untested'),
            'sms_validated_at' => $row['sms_validated_at'] ?? null,
            'email_enabled' => (int) ($row['email_enabled'] ?? 0) === 1,
            'smtp_host' => (string) ($row['smtp_host'] ?? ''),
            'smtp_port' => (int) ($row['smtp_port'] ?? 587),
            'smtp_crypto' => (string) ($row['smtp_crypto'] ?? 'tls'),
            'smtp_user' => (string) ($row['smtp_user'] ?? ''),
            'email_from_address' => (string) ($row['email_from_address'] ?? ''),
            'email_from_name' => (string) ($row['email_from_name'] ?? 'Portail citoyen'),
            'smtp_secret_set' => $this->hasSecret($row, 'smtp_password_encrypted'),
            'email_configured' => $this->hasSecret($row, 'smtp_password_encrypted')
                || (string) ($row['smtp_host'] ?? '') !== '',
            'email_validation_status' => (string) ($row['email_validation_status'] ?? 'untested'),
            'email_validated_at' => $row['email_validated_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function row(): ?array
    {
        return $this->db->table('tenant_communication_settings')
            ->where('tenant_id', $this->tenantContext->id())
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function requirePermission(int $actorUserId, string $permission): void
    {
        if (! $this->authorization->userHasPermission($actorUserId, $permission)) {
            throw new RuntimeException('Actor is not authorized for this operation.');
        }
    }

    private function text(array $input, string $key, int $max): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('A provider setting is invalid.');
        }

        return $value;
    }

    private function port(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $port = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($port === false) {
            throw new InvalidArgumentException('SMTP port is invalid.');
        }

        return (int) $port;
    }

    private function bool(array $input, string $key): bool
    {
        return in_array($input[$key] ?? null, [true, 1, '1', 'on', 'yes'], true);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function hasSecret(array $row, string $column): bool
    {
        return is_string($row[$column] ?? null) && $row[$column] !== '';
    }

    private function secretWasChanged(array $input, string $key): bool
    {
        return trim((string) ($input[$key] ?? '')) !== '' || $this->bool($input, 'clear_' . $key);
    }

    private function channel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        if (! in_array($channel, ['whatsapp', 'sms', 'email'], true)) {
            throw new InvalidArgumentException('Unknown communication channel.');
        }

        return $channel;
    }

    private function testTransport(string $channel, array $values): OtpTransportInterface
    {
        return match ($channel) {
            'whatsapp' => new MetaWhatsAppOtpTransport(
                (string) $values['whatsapp_graph_version'],
                (string) $values['whatsapp_phone_number_id'],
                $this->requiredSecret($values, 'whatsapp_access_token_encrypted', 'whatsapp_access_token'),
                (string) $values['whatsapp_template_name'],
                (string) $values['whatsapp_template_language']
            ),
            'sms' => new TwilioSmsOtpTransport(
                (string) $values['twilio_account_sid'],
                $this->requiredSecret($values, 'twilio_auth_token_encrypted', 'twilio_auth_token'),
                $this->nullable($values['twilio_from_number']),
                $this->nullable($values['twilio_messaging_service_sid'])
            ),
            default => new SmtpEmailOtpTransport(
                (string) $values['smtp_host'],
                (int) $values['smtp_port'],
                (string) $values['smtp_crypto'],
                (string) $values['smtp_user'],
                $this->requiredSecret($values, 'smtp_password_encrypted', 'smtp_password'),
                (string) $values['email_from_address'],
                (string) $values['email_from_name']
            ),
        };
    }

    private function testRequest(string $channel, string $destination): OtpDeliveryRequest
    {
        $destination = trim($destination);

        if ($channel === 'email') {
            $email = strtolower($destination);

            if (
                strlen($email) > 254
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            ) {
                throw new InvalidArgumentException('Test email recipient is invalid.');
            }

            return new OtpDeliveryRequest('+50900000000', '000000', 300, $email);
        }

        $phone = (new IdentityInputNormalizer())->normalizeHaitiPhone($destination);

        if ($phone === null) {
            throw new InvalidArgumentException('Test phone recipient is invalid.');
        }

        return new OtpDeliveryRequest($phone, '000000', 300);
    }

    private function saveValidatedChannel(
        int $actorUserId,
        string $channel,
        array $values,
        array $input,
        ?array $current
    ): void {
        $keys = match ($channel) {
            'whatsapp' => [
                'whatsapp_enabled', 'whatsapp_graph_version',
                'whatsapp_phone_number_id', 'whatsapp_access_token_encrypted',
                'whatsapp_template_name', 'whatsapp_template_language',
            ],
            'sms' => [
                'sms_enabled', 'twilio_account_sid', 'twilio_auth_token_encrypted',
                'twilio_from_number', 'twilio_messaging_service_sid',
            ],
            default => [
                'email_enabled', 'smtp_host', 'smtp_port', 'smtp_crypto',
                'smtp_user', 'smtp_password_encrypted', 'email_from_address',
                'email_from_name',
            ],
        };
        $save = array_intersect_key($values, array_flip($keys));
        $now = gmdate('Y-m-d H:i:s');
        $save[$channel . '_validation_status'] = 'valid';
        $save[$channel . '_validated_at'] = $now;
        $save['updated_by_user_id'] = $actorUserId;
        $save['updated_at'] = $now;

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start channel validation transaction.');
            }

            if ($current === null) {
                $save['tenant_id'] = $this->tenantContext->id();
                $save['created_at'] = $now;
                $ok = $this->db->table('tenant_communication_settings')->insert($save);
            } else {
                $ok = $this->db->table('tenant_communication_settings')
                    ->where('tenant_id', $this->tenantContext->id())
                    ->update($save);
            }

            if (! $ok) {
                throw new RuntimeException('Could not save validated communication channel.');
            }

            $this->audit->record(
                event: 'settings.communication_channel_validated',
                actorUserId: $actorUserId,
                entityType: 'tenant_communication_settings',
                entityId: $this->tenantContext->id(),
                context: [
                    'channel' => $channel,
                    'secret_changed' => $this->secretWasChanged($input, match ($channel) {
                        'whatsapp' => 'whatsapp_access_token',
                        'sms' => 'twilio_auth_token',
                        default => 'smtp_password',
                    }),
                ]
            );

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit channel validation.');
            }
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function channelChanged(
        string $channel,
        array $values,
        array $current,
        array $input
    ): bool {
        $keys = match ($channel) {
            'whatsapp' => [
                'whatsapp_enabled', 'whatsapp_graph_version',
                'whatsapp_phone_number_id', 'whatsapp_template_name',
                'whatsapp_template_language',
            ],
            'sms' => [
                'sms_enabled', 'twilio_account_sid', 'twilio_from_number',
                'twilio_messaging_service_sid',
            ],
            default => [
                'email_enabled', 'smtp_host', 'smtp_port', 'smtp_crypto',
                'smtp_user', 'email_from_address', 'email_from_name',
            ],
        };

        foreach ($keys as $key) {
            if ((string) ($values[$key] ?? '') !== (string) ($current[$key] ?? '')) {
                return true;
            }
        }

        return $this->secretWasChanged($input, match ($channel) {
            'whatsapp' => 'whatsapp_access_token',
            'sms' => 'twilio_auth_token',
            default => 'smtp_password',
        });
    }
}
