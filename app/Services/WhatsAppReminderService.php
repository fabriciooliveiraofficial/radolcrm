<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use RuntimeException;

final class WhatsAppReminderService
{
    public const VARIABLES = [
        'nome' => 'Nome completo do cliente',
        'primeiro_nome' => 'Primeiro nome do cliente',
        'empresa_cliente' => 'Empresa do cliente',
        'telefone' => 'Telefone do cliente',
        'pais' => 'País do cliente',
        'produto' => 'Produto ou plano contratado',
        'data_vencimento' => 'Data de vencimento',
        'valor' => 'Valor da assinatura com moeda',
        'moeda' => 'Moeda da assinatura',
        'dias_para_vencimento' => 'Dias restantes até o vencimento',
        'dias_atraso' => 'Dias desde o vencimento',
        'forma_pagamento' => 'Forma de pagamento cadastrada',
        'empresa' => 'Nome da sua empresa',
    ];

    private const DEFAULTS = [
        'whatsapp_enabled' => '0',
        'whatsapp_instance_id' => '',
        'whatsapp_instance_token' => '',
        'whatsapp_client_token' => '',
        'whatsapp_send_time' => '09:00',
        'whatsapp_upcoming_enabled' => '1',
        'whatsapp_upcoming_start_days' => '1',
        'whatsapp_upcoming_interval_days' => '1',
        'whatsapp_upcoming_max_sends' => '1',
        'whatsapp_overdue_enabled' => '1',
        'whatsapp_overdue_start_days' => '1',
        'whatsapp_overdue_interval_days' => '3',
        'whatsapp_overdue_max_sends' => '3',
        'whatsapp_upcoming_message' => "Olá, {{primeiro_nome}}! Lembramos que sua assinatura {{produto}} vence em {{data_vencimento}}, no valor de {{valor}}. Se já realizou o pagamento, desconsidere esta mensagem. Atenciosamente, {{empresa}}.",
        'whatsapp_overdue_message' => "Olá, {{primeiro_nome}}! Identificamos que sua assinatura {{produto}}, no valor de {{valor}}, venceu em {{data_vencimento}}. Entre em contato conosco para regularizar. Se já realizou o pagamento, desconsidere esta mensagem. Atenciosamente, {{empresa}}.",
        'whatsapp_last_run_at' => '',
        'whatsapp_last_run_summary' => '',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function config(): array
    {
        $rows = $this->db->fetchAll("SELECT setting_key,setting_value FROM settings WHERE setting_key LIKE 'whatsapp_%'");
        $config = self::DEFAULTS;
        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $config)) {
                $config[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        }

        return $config;
    }

    public function saveConfig(array $input): void
    {
        $current = $this->config();
        $config = $current;
        foreach (['whatsapp_enabled', 'whatsapp_upcoming_enabled', 'whatsapp_overdue_enabled'] as $key) {
            $config[$key] = isset($input[$key]) && (string) $input[$key] === '1' ? '1' : '0';
        }
        foreach (['whatsapp_instance_id', 'whatsapp_instance_token', 'whatsapp_client_token'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '') {
                $config[$key] = $value;
            }
        }

        $time = trim((string) ($input['whatsapp_send_time'] ?? '09:00'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new RuntimeException('Informe um horário válido para os lembretes.');
        }
        $config['whatsapp_send_time'] = $time;

        $limits = [
            'whatsapp_upcoming_start_days' => [1, 30],
            'whatsapp_upcoming_interval_days' => [1, 30],
            'whatsapp_upcoming_max_sends' => [1, 10],
            'whatsapp_overdue_start_days' => [1, 365],
            'whatsapp_overdue_interval_days' => [1, 365],
            'whatsapp_overdue_max_sends' => [1, 20],
        ];
        foreach ($limits as $key => [$minimum, $maximum]) {
            $value = (int) ($input[$key] ?? $current[$key]);
            if ($value < $minimum || $value > $maximum) {
                throw new RuntimeException('Uma das regras de frequência está fora do limite permitido.');
            }
            $config[$key] = (string) $value;
        }

        foreach (['whatsapp_upcoming_message', 'whatsapp_overdue_message'] as $key) {
            $message = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input[$key] ?? '')));
            if ($message === '' || mb_strlen($message) > 4000) {
                throw new RuntimeException('Cada modelo deve ter entre 1 e 4.000 caracteres.');
            }
            $unknown = self::unknownVariables($message);
            if ($unknown) {
                throw new RuntimeException('Variável não reconhecida no modelo: {{' . $unknown[0] . '}}.');
            }
            $config[$key] = $message;
        }

        if ($config['whatsapp_enabled'] === '1') {
            if (!$this->hasCredentials($config)) {
                throw new RuntimeException('Informe o ID e o token da instância Z-API antes de ativar os envios.');
            }
            if ($config['whatsapp_upcoming_enabled'] !== '1' && $config['whatsapp_overdue_enabled'] !== '1') {
                throw new RuntimeException('Ative pelo menos um tipo de lembrete.');
            }
        }

        $persisted = array_diff_key($config, array_flip(['whatsapp_last_run_at', 'whatsapp_last_run_summary']));
        $this->db->transaction(function (Database $db) use ($persisted): void {
            foreach ($persisted as $key => $value) {
                $db->query(
                    'INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',
                    [$key, $value]
                );
            }
        });
    }

    public function hasCredentials(?array $config = null): bool
    {
        $config ??= $this->config();
        return trim($config['whatsapp_instance_id']) !== '' && trim($config['whatsapp_instance_token']) !== '';
    }

    public function testConnection(): array
    {
        $config = $this->config();
        if (!$this->hasCredentials($config)) {
            throw new RuntimeException('Configure o ID e o token da instância Z-API primeiro.');
        }
        $response = $this->request('GET', 'status', null, $config);

        return [
            'connected' => !empty($response['connected']),
            'smartphoneConnected' => !empty($response['smartphoneConnected']),
            'message' => (string) ($response['error'] ?? ''),
        ];
    }

    public function run(bool $force = false): array
    {
        $config = $this->config();
        if ($config['whatsapp_enabled'] !== '1') {
            return $this->finishRun(['status' => 'disabled', 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicates' => 0]);
        }
        if (!$this->hasCredentials($config)) {
            throw new RuntimeException('As credenciais da Z-API estão incompletas.');
        }
        if (!$force && date('H:i') < $config['whatsapp_send_time']) {
            return ['status' => 'waiting', 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicates' => 0];
        }
        $lockName = 'nexo_whatsapp_' . substr(hash('sha256', (string) $this->db->value('SELECT DATABASE()')), 0, 32);
        if ((int) $this->db->value('SELECT GET_LOCK(?,0)', [$lockName]) !== 1) {
            return ['status' => 'locked', 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicates' => 0];
        }

        try {
            $summary = ['status' => 'processed', 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicates' => 0];
            if ($config['whatsapp_upcoming_enabled'] === '1') {
                $start = (int) $config['whatsapp_upcoming_start_days'];
                $rows = $this->db->fetchAll(
                    "SELECT s.id subscription_id,s.next_billing_date,s.currency,s.unit_price,s.quantity,s.discount,s.payment_method,
                            c.id client_id,c.name client,c.company,c.phone,c.country,p.name product,
                            DATEDIFF(s.next_billing_date,CURDATE()) day_offset
                     FROM subscriptions s
                     JOIN clients c ON c.id=s.client_id
                     JOIN products p ON p.id=s.product_id
                     WHERE s.status IN ('active','trial','past_due')
                       AND DATEDIFF(s.next_billing_date,CURDATE()) BETWEEN 1 AND ?
                       AND NOT EXISTS (
                           SELECT 1 FROM payments paid
                           WHERE paid.subscription_id=s.id AND paid.due_date=s.next_billing_date AND paid.status='paid'
                       )
                     ORDER BY s.next_billing_date,c.name",
                    [$start]
                );
                $this->processRows('upcoming', $rows, $config, $summary);
            }

            if ($config['whatsapp_overdue_enabled'] === '1') {
                $start = (int) $config['whatsapp_overdue_start_days'];
                $interval = (int) $config['whatsapp_overdue_interval_days'];
                $maximum = $start + (((int) $config['whatsapp_overdue_max_sends'] - 1) * $interval);
                $rows = $this->db->fetchAll(
                    "SELECT s.id subscription_id,s.next_billing_date,s.currency,s.unit_price,s.quantity,s.discount,s.payment_method,
                            c.id client_id,c.name client,c.company,c.phone,c.country,p.name product,
                            DATEDIFF(s.next_billing_date,CURDATE()) day_offset
                     FROM subscriptions s
                     JOIN clients c ON c.id=s.client_id
                     JOIN products p ON p.id=s.product_id
                     WHERE s.status IN ('active','trial','past_due')
                       AND DATEDIFF(CURDATE(),s.next_billing_date) BETWEEN ? AND ?
                       AND NOT EXISTS (
                           SELECT 1 FROM payments paid
                           WHERE paid.subscription_id=s.id AND paid.due_date=s.next_billing_date AND paid.status='paid'
                       )
                     ORDER BY s.next_billing_date,c.name",
                    [$start, $maximum]
                );
                $this->processRows('overdue', $rows, $config, $summary);
            }

            return $this->finishRun($summary);
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    public function history(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->fetchAll(
            "SELECT l.*,c.name client,p.name product
             FROM whatsapp_reminder_logs l
             LEFT JOIN clients c ON c.id=l.client_id
             LEFT JOIN subscriptions s ON s.id=l.subscription_id
             LEFT JOIN products p ON p.id=s.product_id
             ORDER BY l.created_at DESC,l.id DESC LIMIT {$limit}"
        );
    }

    public function stats(): array
    {
        $stats = $this->db->fetch(
            "SELECT
                COALESCE(SUM(status='sent' AND DATE(sent_at)=CURDATE()),0) sent_today,
                COALESCE(SUM(status='failed' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)),0) failed_recent,
                COALESCE(SUM(status='skipped' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) skipped_recent,
                COALESCE(SUM(reminder_type='upcoming' AND status='sent' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) upcoming_sent,
                COALESCE(SUM(reminder_type='overdue' AND status='sent' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) overdue_sent
             FROM whatsapp_reminder_logs"
        ) ?? [];
        return array_map('intval', $stats);
    }

    public static function renderTemplate(string $template, array $values): string
    {
        $replacements = [];
        foreach (self::VARIABLES as $variable => $_label) {
            $replacements['{{' . $variable . '}}'] = (string) ($values[$variable] ?? '');
        }
        return strtr($template, $replacements);
    }

    public static function unknownVariables(string $template): array
    {
        preg_match_all('/{{([a-z_]+)}}/i', $template, $matches);
        return array_values(array_diff(array_unique($matches[1] ?? []), array_keys(self::VARIABLES)));
    }

    public static function normalizePhone(string $phone, string $country): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = preg_replace('/^00/', '', $digits) ?? '';
        if ($country === 'BR' && in_array(strlen($digits), [10, 11], true)) {
            $digits = '55' . $digits;
        } elseif ($country === 'US' && strlen($digits) === 10) {
            $digits = '1' . $digits;
        }
        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }

    private function processRows(string $type, array $rows, array $config, array &$summary): void
    {
        $prefix = $type === 'upcoming' ? 'whatsapp_upcoming_' : 'whatsapp_overdue_';
        $start = (int) $config[$prefix . 'start_days'];
        $interval = (int) $config[$prefix . 'interval_days'];
        $maximum = (int) $config[$prefix . 'max_sends'];
        $template = $config[$prefix . 'message'];

        foreach ($rows as $row) {
            $offset = abs((int) $row['day_offset']);
            $elapsed = $type === 'upcoming' ? $start - $offset : $offset - $start;
            if ($elapsed < 0 || $elapsed % $interval !== 0) {
                continue;
            }
            $reminderNumber = intdiv($elapsed, $interval) + 1;
            if ($reminderNumber > $maximum) {
                continue;
            }
            $this->deliver($type, $reminderNumber, $row, $template, $config, $summary);
        }
    }

    private function deliver(string $type, int $reminderNumber, array $row, string $template, array $config, array &$summary): void
    {
        $existing = $this->db->fetch(
            'SELECT * FROM whatsapp_reminder_logs WHERE subscription_id=? AND due_date=? AND reminder_type=? AND reminder_number=?',
            [$row['subscription_id'], $row['next_billing_date'], $type, $reminderNumber]
        );
        if ($existing && in_array($existing['status'], ['sent', 'skipped'], true)) {
            $summary['duplicates']++;
            return;
        }
        if ($existing && ((int) $existing['attempts'] >= 3 || ($existing['last_attempt_at'] && strtotime($existing['last_attempt_at']) > time() - 900))) {
            $summary['duplicates']++;
            return;
        }

        $rawPhone = mb_substr((string) ($row['phone'] ?? ''), 0, 30);
        $phone = self::normalizePhone($rawPhone, (string) $row['country']);
        $message = self::renderTemplate($template, $this->templateValues($row, $config));
        if (!$existing) {
            $logId = $this->db->insert(
                "INSERT INTO whatsapp_reminder_logs
                    (subscription_id,client_id,reminder_type,reminder_number,due_date,recipient_phone,rendered_message,status)
                 VALUES (?,?,?,?,?,?,?,'pending')",
                [$row['subscription_id'],$row['client_id'],$type,$reminderNumber,$row['next_billing_date'],$phone ?: $rawPhone,$message]
            );
        } else {
            $logId = (int) $existing['id'];
            $this->db->query(
                "UPDATE whatsapp_reminder_logs SET status='pending',recipient_phone=?,rendered_message=?,error_message=NULL WHERE id=?",
                [$phone ?: $rawPhone,$message,$logId]
            );
        }

        if (!$phone) {
            $this->db->query(
                "UPDATE whatsapp_reminder_logs SET status='skipped',error_message='Telefone ausente ou inválido',last_attempt_at=NOW() WHERE id=?",
                [$logId]
            );
            $summary['skipped']++;
            return;
        }

        try {
            $response = $this->request('POST', 'send-text', ['phone' => $phone, 'message' => $message], $config);
            $messageId = (string) ($response['messageId'] ?? $response['id'] ?? $response['zaapId'] ?? '');
            $this->db->query(
                "UPDATE whatsapp_reminder_logs
                 SET status='sent',attempts=attempts+1,provider_message_id=?,provider_response=?,error_message=NULL,last_attempt_at=NOW(),sent_at=NOW()
                 WHERE id=?",
                [$messageId,json_encode($response, JSON_UNESCAPED_UNICODE),$logId]
            );
            $summary['sent']++;
        } catch (\Throwable $exception) {
            $this->db->query(
                "UPDATE whatsapp_reminder_logs
                 SET status='failed',attempts=attempts+1,error_message=?,last_attempt_at=NOW() WHERE id=?",
                [mb_substr($exception->getMessage(), 0, 500),$logId]
            );
            $summary['failed']++;
        }
    }

    private function templateValues(array $row, array $config): array
    {
        $name = trim((string) $row['client']);
        $firstName = preg_split('/\s+/', $name)[0] ?? $name;
        $amount = max(0, ((float) $row['unit_price'] * (int) $row['quantity']) - (float) $row['discount']);
        $days = abs((int) $row['day_offset']);
        return [
            'nome' => $name,
            'primeiro_nome' => $firstName,
            'empresa_cliente' => (string) ($row['company'] ?? ''),
            'telefone' => (string) ($row['phone'] ?? ''),
            'pais' => $row['country'] === 'BR' ? 'Brasil' : 'Estados Unidos',
            'produto' => (string) $row['product'],
            'data_vencimento' => (new DateTimeImmutable($row['next_billing_date']))->format('d/m/Y'),
            'valor' => \money($amount, (string) $row['currency']),
            'moeda' => (string) $row['currency'],
            'dias_para_vencimento' => (string) ((int) $row['day_offset'] > 0 ? $days : 0),
            'dias_atraso' => (string) ((int) $row['day_offset'] < 0 ? $days : 0),
            'forma_pagamento' => (string) ($row['payment_method'] ?? ''),
            'empresa' => (string) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='company_name'") ?: 'Nossa equipe'),
        ];
    }

    private function request(string $method, string $endpoint, ?array $payload, array $config): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A extensão cURL do PHP é necessária para acessar a Z-API.');
        }
        $url = sprintf(
            'https://api.z-api.io/instances/%s/token/%s/%s',
            rawurlencode($config['whatsapp_instance_id']),
            rawurlencode($config['whatsapp_instance_token']),
            ltrim($endpoint, '/')
        );
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if (trim($config['whatsapp_client_token']) !== '') {
            $headers[] = 'Client-Token: ' . $config['whatsapp_client_token'];
        }

        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Não foi possível conectar à Z-API: ' . ($curlError ?: 'erro de rede.'));
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? '') : '';
            throw new RuntimeException('A Z-API recusou a solicitação (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $message : '.'));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('A Z-API retornou uma resposta inválida.');
        }
        return $decoded;
    }

    private function finishRun(array $summary): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            "INSERT INTO settings (setting_key,setting_value) VALUES ('whatsapp_last_run_at',?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
            [$now]
        );
        $this->db->query(
            "INSERT INTO settings (setting_key,setting_value) VALUES ('whatsapp_last_run_summary',?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
            [json_encode($summary, JSON_UNESCAPED_UNICODE)]
        );
        return $summary;
    }
}
