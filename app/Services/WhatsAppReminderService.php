<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
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
        'quantidade' => 'Quantidade de unidades da assinatura',
        'data_hoje' => 'Data atual em São Paulo',
        'data_vencimento' => 'Próxima data de pagamento',
        'valor' => 'Valor recorrente da assinatura com moeda',
        'moeda' => 'Moeda da assinatura',
        'dias_para_vencimento' => 'Dias restantes até o vencimento',
        'dias_para_vencer' => 'Alias de dias para vencimento',
        'dias_atraso' => 'Dias desde o vencimento',
        'dias_em_atraso' => 'Alias de dias em atraso',
        'forma_pagamento' => 'Forma de pagamento cadastrada',
        'link_pagamento' => 'Link de pagamento da assinatura ou da etapa',
        'telefone_suporte' => 'Telefone de suporte configurado',
        'id_cliente' => 'Identificador do cliente',
        'id_assinatura' => 'Identificador da assinatura',
        'empresa' => 'Nome da sua empresa',
    ];

    private const DEFAULTS = [
        'whatsapp_enabled' => '0',
        'whatsapp_instance_id' => '',
        'whatsapp_instance_token' => '',
        'whatsapp_client_token' => '',
        'whatsapp_timezone' => 'America/Sao_Paulo',
        'whatsapp_window_start' => '08:00',
        'whatsapp_window_end' => '19:00',
        'whatsapp_allowed_weekdays' => '1,2,3,4,5,6,7',
        'whatsapp_daily_limit' => '200',
        'whatsapp_max_per_client_daily' => '2',
        'whatsapp_max_attempts' => '3',
        'whatsapp_retry_delay_minutes' => '15',
        'whatsapp_support_phone' => '',
        'whatsapp_test_phone' => '',
        'whatsapp_test_country' => 'BR',
        'whatsapp_upcoming_enabled' => '1',
        'whatsapp_overdue_enabled' => '1',
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
        $config['whatsapp_timezone'] = 'America/Sao_Paulo';

        return $config;
    }

    public function steps(?string $type = null, bool $onlyActive = false): array
    {
        $where = [];
        $params = [];
        if (in_array($type, ['upcoming', 'overdue'], true)) {
            $where[] = 'reminder_type=?';
            $params[] = $type;
        }
        if ($onlyActive) {
            $where[] = 'active=1';
        }
        $sql = 'SELECT * FROM whatsapp_automation_steps'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY FIELD(reminder_type,'upcoming','overdue'),position,day_offset,id";

        return $this->db->fetchAll($sql, $params);
    }

    public function stepsByType(): array
    {
        $grouped = ['upcoming' => [], 'overdue' => []];
        foreach ($this->steps() as $step) {
            $grouped[$step['reminder_type']][] = $step;
        }

        return $grouped;
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

        $config['whatsapp_timezone'] = 'America/Sao_Paulo';
        foreach (['whatsapp_window_start', 'whatsapp_window_end'] as $key) {
            $value = trim((string) ($input[$key] ?? $current[$key]));
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
                throw new RuntimeException('Informe uma janela de envio válida.');
            }
            $config[$key] = $value;
        }
        if ($config['whatsapp_window_start'] >= $config['whatsapp_window_end']) {
            throw new RuntimeException('O início da janela de envio precisa ser anterior ao término.');
        }

        $weekdays = array_values(array_unique(array_filter(
            array_map('intval', (array) ($input['whatsapp_allowed_weekdays'] ?? [])),
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($weekdays);
        if (!$weekdays) {
            throw new RuntimeException('Selecione ao menos um dia da semana para os envios.');
        }
        $config['whatsapp_allowed_weekdays'] = implode(',', $weekdays);

        $limits = [
            'whatsapp_daily_limit' => [1, 5000],
            'whatsapp_max_per_client_daily' => [1, 20],
            'whatsapp_max_attempts' => [1, 10],
            'whatsapp_retry_delay_minutes' => [1, 1440],
        ];
        foreach ($limits as $key => [$minimum, $maximum]) {
            $value = (int) ($input[$key] ?? $current[$key]);
            if ($value < $minimum || $value > $maximum) {
                throw new RuntimeException('Um dos limites de segurança está fora do intervalo permitido.');
            }
            $config[$key] = (string) $value;
        }
        $config['whatsapp_support_phone'] = mb_substr(trim((string) ($input['whatsapp_support_phone'] ?? '')), 0, 50);

        if ($config['whatsapp_enabled'] === '1') {
            if (!$this->hasCredentials($config)) {
                throw new RuntimeException('Informe o ID, o token da instância e o Client-Token da Z-API antes de ativar os envios.');
            }
            if ($config['whatsapp_upcoming_enabled'] !== '1' && $config['whatsapp_overdue_enabled'] !== '1') {
                throw new RuntimeException('Ative ao menos uma das automações.');
            }
        }

        $steps = $this->validatedSteps((array) ($input['steps'] ?? []), $config);
        foreach (['upcoming', 'overdue'] as $type) {
            if ($config['whatsapp_' . $type . '_enabled'] === '1'
                && !array_filter($steps[$type], static fn(array $step): bool => $step['active'] === 1)) {
                throw new RuntimeException('Cada automação ativa precisa ter ao menos uma etapa ativa.');
            }
        }

        $persisted = array_diff_key($config, array_flip(['whatsapp_last_run_at', 'whatsapp_last_run_summary']));
        $this->db->transaction(function (Database $db) use ($persisted, $steps): void {
            foreach ($persisted as $key => $value) {
                $db->query(
                    'INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',
                    [$key, $value]
                );
            }
            foreach (['upcoming', 'overdue'] as $type) {
                $keptIds = [];
                $db->query(
                    'UPDATE whatsapp_automation_steps SET day_offset=day_offset+10000 WHERE reminder_type=?',
                    [$type]
                );
                foreach ($steps[$type] as $step) {
                    if ($step['id'] > 0) {
                        $exists = $db->value(
                            'SELECT id FROM whatsapp_automation_steps WHERE id=? AND reminder_type=?',
                            [$step['id'], $type]
                        );
                        if (!$exists) {
                            throw new RuntimeException('Uma etapa foi alterada por outro usuário. Atualize a página.');
                        }
                        $db->query(
                            'UPDATE whatsapp_automation_steps
                             SET name=?,day_offset=?,send_time=?,message_template=?,image_url=?,payment_link=?,active=?,position=?
                             WHERE id=? AND reminder_type=?',
                            [
                                $step['name'],$step['day_offset'],$step['send_time'],$step['message_template'],
                                $step['image_url'],$step['payment_link'],$step['active'],$step['position'],$step['id'],$type,
                            ]
                        );
                        $keptIds[] = $step['id'];
                    } else {
                        $keptIds[] = $db->insert(
                            'INSERT INTO whatsapp_automation_steps
                             (reminder_type,name,day_offset,send_time,message_template,image_url,payment_link,active,position)
                             VALUES (?,?,?,?,?,?,?,?,?)',
                            [
                                $type,$step['name'],$step['day_offset'],$step['send_time'],$step['message_template'],
                                $step['image_url'],$step['payment_link'],$step['active'],$step['position'],
                            ]
                        );
                    }
                }
                if ($keptIds) {
                    $placeholders = implode(',', array_fill(0, count($keptIds), '?'));
                    $db->query(
                        "DELETE FROM whatsapp_automation_steps WHERE reminder_type=? AND id NOT IN ({$placeholders})",
                        array_merge([$type], $keptIds)
                    );
                } else {
                    $db->query('DELETE FROM whatsapp_automation_steps WHERE reminder_type=?', [$type]);
                }
            }
        });
    }

    public function hasCredentials(?array $config = null): bool
    {
        $config ??= $this->config();

        return trim($config['whatsapp_instance_id']) !== ''
            && trim($config['whatsapp_instance_token']) !== ''
            && trim($config['whatsapp_client_token']) !== '';
    }

    public function testConnection(): array
    {
        $config = $this->config();
        if (!$this->hasCredentials($config)) {
            throw new RuntimeException('Configure o ID, o token da instância e o Client-Token da Z-API primeiro.');
        }
        $response = $this->request('GET', 'status', null, $config);

        return [
            'connected' => !empty($response['connected']),
            'smartphoneConnected' => !empty($response['smartphoneConnected']),
            'message' => (string) ($response['error'] ?? ''),
        ];
    }

    public function sendTest(string $rawPhone, string $country, int $stepId): array
    {
        $config = $this->config();
        if (!$this->hasCredentials($config)) {
            throw new RuntimeException('Configure as credenciais da Z-API antes de enviar o teste.');
        }
        $country = in_array($country, ['BR', 'US'], true) ? $country : 'BR';
        $phone = self::normalizePhone($rawPhone, $country);
        if (!$phone) {
            throw new RuntimeException('Informe um telefone de teste válido, com DDD.');
        }
        $step = $this->db->fetch('SELECT * FROM whatsapp_automation_steps WHERE id=?', [$stepId]);
        if (!$step) {
            throw new RuntimeException('Selecione uma etapa válida para o teste.');
        }

        $now = $this->now($config);
        $offset = (int) $step['day_offset'];
        $dueDate = $step['reminder_type'] === 'upcoming'
            ? $now->modify("+{$offset} days")
            : $now->modify("-{$offset} days");
        $values = [
            'nome' => 'Mariana Oliveira',
            'primeiro_nome' => 'Mariana',
            'empresa_cliente' => 'Oliveira Digital',
            'telefone' => $phone,
            'pais' => $country === 'BR' ? 'Brasil' : 'Estados Unidos',
            'produto' => 'Plano Premium',
            'quantidade' => '2',
            'data_hoje' => $now->format('d/m/Y'),
            'data_vencimento' => $dueDate->format('d/m/Y'),
            'valor' => $country === 'BR' ? 'R$ 149,90' : 'US$ 29,90',
            'moeda' => $country === 'BR' ? 'BRL' : 'USD',
            'dias_para_vencimento' => $step['reminder_type'] === 'upcoming' ? (string) $offset : '0',
            'dias_para_vencer' => $step['reminder_type'] === 'upcoming' ? (string) $offset : '0',
            'dias_atraso' => $step['reminder_type'] === 'overdue' ? (string) $offset : '0',
            'dias_em_atraso' => $step['reminder_type'] === 'overdue' ? (string) $offset : '0',
            'forma_pagamento' => 'PIX',
            'link_pagamento' => '',
            'telefone_suporte' => $config['whatsapp_support_phone'],
            'id_cliente' => '1001',
            'id_assinatura' => '5001',
            'empresa' => (string) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='company_name'") ?: 'Nossa equipe'),
        ];
        $paymentLink = $this->renderPaymentLink((string) ($step['payment_link'] ?? ''), $values);
        $values['link_pagamento'] = $paymentLink;
        $message = self::renderTemplate((string) $step['message_template'], $values);
        $response = $this->sendPayload($phone, $message, (string) ($step['image_url'] ?? ''), $config);

        $this->db->query(
            "INSERT INTO settings (setting_key,setting_value) VALUES ('whatsapp_test_phone',?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
            [$rawPhone]
        );
        $this->db->query(
            "INSERT INTO settings (setting_key,setting_value) VALUES ('whatsapp_test_country',?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
            [$country]
        );

        return $response;
    }

    public function run(bool $force = false): array
    {
        $config = $this->config();
        if ($config['whatsapp_enabled'] !== '1') {
            return $this->finishRun($this->emptySummary('disabled'));
        }
        if (!$this->hasCredentials($config)) {
            throw new RuntimeException('As credenciais da Z-API estão incompletas.');
        }

        $now = $this->now($config);
        if (!$force && !$this->isInsideSchedule($now, $config)) {
            return $this->emptySummary('waiting');
        }
        $lockName = 'nexo_whatsapp_' . substr(hash('sha256', (string) $this->db->value('SELECT DATABASE()')), 0, 32);
        if ((int) $this->db->value('SELECT GET_LOCK(?,0)', [$lockName]) !== 1) {
            return $this->emptySummary('locked');
        }

        try {
            $summary = $this->emptySummary('processed');
            $today = $now->format('Y-m-d');
            $tomorrow = $now->modify('+1 day')->format('Y-m-d');
            $alreadySent = (int) $this->db->value(
                "SELECT COUNT(*) FROM whatsapp_reminder_logs WHERE status='sent' AND sent_at>=? AND sent_at<?",
                [$today . ' 00:00:00', $tomorrow . ' 00:00:00']
            );
            $dailyLimit = (int) $config['whatsapp_daily_limit'];

            foreach ($this->steps(null, true) as $step) {
                $type = (string) $step['reminder_type'];
                if ($config['whatsapp_' . $type . '_enabled'] !== '1') {
                    continue;
                }
                if (!$force && $now->format('H:i') < substr((string) $step['send_time'], 0, 5)) {
                    continue;
                }
                if ($alreadySent + $summary['sent'] >= $dailyLimit) {
                    $summary['limit_reached'] = true;
                    break;
                }

                $offset = (int) $step['day_offset'];
                $dueDate = $type === 'upcoming'
                    ? $now->modify("+{$offset} days")->format('Y-m-d')
                    : $now->modify("-{$offset} days")->format('Y-m-d');
                $rows = $this->candidateRows($dueDate, $today, $type);
                foreach ($rows as $row) {
                    if ($alreadySent + $summary['sent'] >= $dailyLimit) {
                        $summary['limit_reached'] = true;
                        break 2;
                    }
                    $this->deliver($step, $row, $config, $summary, false);
                }
            }

            return $this->finishRun($summary);
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    public function retry(int $logId): array
    {
        $config = $this->config();
        if (!$this->hasCredentials($config)) {
            throw new RuntimeException('As credenciais da Z-API estão incompletas.');
        }
        $row = $this->db->fetch(
            "SELECT l.id log_id,l.status log_status,l.due_date,
                    s.id subscription_id,s.next_billing_date,s.currency,s.unit_price,s.quantity,s.discount,s.payment_method,s.payment_link,
                    c.id client_id,c.name client,c.company,c.phone,c.country,c.whatsapp_reminders_enabled,p.name product,
                    st.id step_id,st.reminder_type,st.name step_name,st.day_offset,st.send_time,st.message_template,
                    st.image_url,st.payment_link step_payment_link,st.active,st.position
             FROM whatsapp_reminder_logs l
             JOIN whatsapp_automation_steps st ON st.id=l.automation_step_id
             JOIN subscriptions s ON s.id=l.subscription_id
             JOIN clients c ON c.id=s.client_id
             JOIN products p ON p.id=s.product_id
             WHERE l.id=?",
            [$logId]
        );
        if (!$row || $row['log_status'] === 'sent') {
            throw new RuntimeException('Este envio não pode ser reenviado.');
        }
        if (!(bool) $row['whatsapp_reminders_enabled']
            || !in_array($this->db->value('SELECT status FROM subscriptions WHERE id=?', [$row['subscription_id']]), ['active','trial','past_due'], true)
            || $row['next_billing_date'] !== $row['due_date']
            || (int) $this->db->value(
                "SELECT COUNT(*) FROM payments WHERE subscription_id=? AND due_date=? AND status='paid'",
                [$row['subscription_id'], $row['due_date']]
            ) > 0) {
            throw new RuntimeException('A cobrança já foi paga, renovada, pausada ou cancelada. O reenvio foi bloqueado.');
        }
        $today = $this->now($config)->format('Y-m-d');
        $row['day_offset'] = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($row['due_date']))->format('%r%a');
        $step = [
            'id' => $row['step_id'],
            'reminder_type' => $row['reminder_type'],
            'name' => $row['step_name'],
            'day_offset' => $row['day_offset'],
            'send_time' => $row['send_time'],
            'message_template' => $row['message_template'],
            'image_url' => $row['image_url'],
            'payment_link' => $row['step_payment_link'],
            'active' => $row['active'],
            'position' => $row['position'],
        ];
        $summary = $this->emptySummary('retried');
        $this->deliver($step, $row, $config, $summary, true);

        return $summary;
    }

    public function history(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->fetchAll(
            "SELECT l.*,c.name client,p.name product,st.name step_name
             FROM whatsapp_reminder_logs l
             LEFT JOIN clients c ON c.id=l.client_id
             LEFT JOIN subscriptions s ON s.id=l.subscription_id
             LEFT JOIN products p ON p.id=s.product_id
             LEFT JOIN whatsapp_automation_steps st ON st.id=l.automation_step_id
             ORDER BY l.created_at DESC,l.id DESC LIMIT {$limit}"
        );
    }

    public function stats(): array
    {
        $config = $this->config();
        $now = $this->now($config);
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $stats = $this->db->fetch(
            "SELECT
                COALESCE(SUM(status='sent' AND sent_at>=? AND sent_at<?),0) sent_today,
                COALESCE(SUM(status='failed' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)),0) failed_recent,
                COALESCE(SUM(status='skipped' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) skipped_recent,
                COALESCE(SUM(reminder_type='upcoming' AND status='sent' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) upcoming_sent,
                COALESCE(SUM(reminder_type='overdue' AND status='sent' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) overdue_sent
             FROM whatsapp_reminder_logs",
            [$today . ' 00:00:00', $tomorrow . ' 00:00:00']
        ) ?? [];
        $stats = array_map('intval', $stats);
        $stats['upcoming_queue'] = 0;
        $stats['overdue_queue'] = 0;
        foreach ($this->steps(null, true) as $step) {
            $type = (string) $step['reminder_type'];
            if ($config['whatsapp_' . $type . '_enabled'] !== '1') {
                continue;
            }
            $offset = (int) $step['day_offset'];
            $dueDate = $type === 'upcoming'
                ? $now->modify("+{$offset} days")->format('Y-m-d')
                : $now->modify("-{$offset} days")->format('Y-m-d');
            $stats[$type . '_queue'] += $this->candidateCount($dueDate, (int) $step['id']);
        }

        return $stats;
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
        $country = strtoupper($country);
        if ($country === 'BR' && in_array(strlen($digits), [10, 11], true)) {
            $digits = '55' . $digits;
        } elseif ($country === 'US' && strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        return strlen($digits) >= 11 && strlen($digits) <= 15 ? $digits : null;
    }

    private function validatedSteps(array $input, array $config): array
    {
        $validated = ['upcoming' => [], 'overdue' => []];
        foreach (['upcoming', 'overdue'] as $type) {
            $rows = array_values((array) ($input[$type] ?? []));
            if (count($rows) > 20) {
                throw new RuntimeException('Cada automação pode ter no máximo 20 etapas.');
            }
            $offsets = [];
            foreach ($rows as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $offset = (int) ($row['day_offset'] ?? 0);
                $maximum = $type === 'upcoming' ? 365 : 730;
                if ($offset < 1 || $offset > $maximum || in_array($offset, $offsets, true)) {
                    throw new RuntimeException('As etapas precisam ter dias válidos e não podem repetir o mesmo dia.');
                }
                $offsets[] = $offset;
                $time = substr(trim((string) ($row['send_time'] ?? '09:00')), 0, 5);
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                    throw new RuntimeException('Uma etapa contém um horário inválido.');
                }
                $message = trim(str_replace(["\r\n", "\r"], "\n", (string) ($row['message_template'] ?? '')));
                if ($message === '' || mb_strlen($message) > 4000) {
                    throw new RuntimeException('Cada mensagem deve ter entre 1 e 4.000 caracteres.');
                }
                $unknown = self::unknownVariables($message);
                if ($unknown) {
                    throw new RuntimeException('Variável não reconhecida: {{' . $unknown[0] . '}}.');
                }
                $existingImage = trim((string) ($row['existing_image'] ?? ''));
                $image = trim((string) ($row['uploaded_image'] ?? ''));
                if ($image === '') {
                    $image = trim((string) ($row['image_url'] ?? ''));
                }
                if ($image === '' && empty($row['remove_image'])) {
                    $image = $existingImage;
                }
                if ($image !== ''
                    && !str_starts_with($image, 'storage/reminders/')
                    && !preg_match('#^https?://#i', $image)) {
                    throw new RuntimeException('A imagem deve ser um upload válido ou uma URL HTTP/HTTPS.');
                }
                $paymentLink = trim((string) ($row['payment_link'] ?? ''));
                if (mb_strlen($paymentLink) > 1000
                    || ($paymentLink !== '' && !preg_match('#^https?://#i', $paymentLink))) {
                    throw new RuntimeException('O link de pagamento deve começar com http:// ou https://.');
                }
                $unknownLinkVariables = self::unknownVariables($paymentLink);
                if ($unknownLinkVariables) {
                    throw new RuntimeException('Variável não reconhecida no link: {{' . $unknownLinkVariables[0] . '}}.');
                }
                $validated[$type][] = [
                    'id' => max(0, (int) ($row['id'] ?? 0)),
                    'name' => mb_substr(trim((string) ($row['name'] ?? '')) ?: ($type === 'upcoming' ? 'Lembrete' : 'Recuperação'), 0, 100),
                    'day_offset' => $offset,
                    'send_time' => $time,
                    'message_template' => $message,
                    'image_url' => $image !== '' ? $image : null,
                    'payment_link' => $paymentLink !== '' ? $paymentLink : null,
                    'active' => isset($row['active']) && (string) $row['active'] === '1' ? 1 : 0,
                    'position' => $index + 1,
                ];
            }
        }

        return $validated;
    }

    private function isInsideSchedule(DateTimeImmutable $now, array $config): bool
    {
        $weekdays = array_map('intval', explode(',', $config['whatsapp_allowed_weekdays']));
        if (!in_array((int) $now->format('N'), $weekdays, true)) {
            return false;
        }
        $time = $now->format('H:i');

        return $time >= $config['whatsapp_window_start'] && $time <= $config['whatsapp_window_end'];
    }

    private function candidateRows(string $dueDate, string $today, string $type): array
    {
        return $this->db->fetchAll(
            "SELECT s.id subscription_id,s.next_billing_date,s.currency,s.unit_price,s.quantity,s.discount,
                    s.payment_method,s.payment_link,
                    c.id client_id,c.name client,c.company,c.phone,c.country,p.name product,
                    DATEDIFF(s.next_billing_date,?) day_offset
             FROM subscriptions s
             JOIN clients c ON c.id=s.client_id
             JOIN products p ON p.id=s.product_id
             WHERE s.status IN ('active','trial','past_due')
               AND c.status='active'
               AND c.whatsapp_reminders_enabled=1
               AND s.next_billing_date=?
               AND NOT EXISTS (
                   SELECT 1 FROM payments paid
                   WHERE paid.subscription_id=s.id AND paid.due_date=s.next_billing_date AND paid.status='paid'
               )
             ORDER BY c.name,s.id",
            [$today, $dueDate]
        );
    }

    private function candidateCount(string $dueDate, int $stepId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*)
             FROM subscriptions s
             JOIN clients c ON c.id=s.client_id
             WHERE s.status IN ('active','trial','past_due')
               AND c.status='active'
               AND c.whatsapp_reminders_enabled=1
               AND s.next_billing_date=?
               AND NOT EXISTS (
                   SELECT 1 FROM payments paid
                   WHERE paid.subscription_id=s.id AND paid.due_date=s.next_billing_date AND paid.status='paid'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM whatsapp_reminder_logs sent
                   WHERE sent.subscription_id=s.id AND sent.due_date=s.next_billing_date
                     AND sent.automation_step_id=? AND sent.status='sent'
               )",
            [$dueDate, $stepId]
        );
    }

    private function deliver(array $step, array $row, array $config, array &$summary, bool $manualRetry): void
    {
        $stepId = (int) $step['id'];
        $existing = $this->db->fetch(
            'SELECT * FROM whatsapp_reminder_logs WHERE subscription_id=? AND due_date=? AND automation_step_id=?',
            [$row['subscription_id'], $row['next_billing_date'], $stepId]
        );
        if ($existing && $existing['status'] === 'sent') {
            $summary['duplicates']++;
            return;
        }
        if (!$manualRetry && $existing && $existing['status'] === 'skipped') {
            $summary['duplicates']++;
            return;
        }
        $maxAttempts = (int) $config['whatsapp_max_attempts'];
        $retryDelay = (int) $config['whatsapp_retry_delay_minutes'] * 60;
        if (!$manualRetry && $existing
            && ((int) $existing['attempts'] >= $maxAttempts
                || ($existing['last_attempt_at'] && strtotime($existing['last_attempt_at']) > time() - $retryDelay))) {
            $summary['duplicates']++;
            return;
        }

        $now = $this->now($config);
        if (!$manualRetry) {
            $clientSends = (int) $this->db->value(
                "SELECT COUNT(*) FROM whatsapp_reminder_logs
                 WHERE client_id=? AND status='sent' AND sent_at>=? AND sent_at<?",
                [
                    $row['client_id'],
                    $now->format('Y-m-d') . ' 00:00:00',
                    $now->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
                ]
            );
            if ($clientSends >= (int) $config['whatsapp_max_per_client_daily']) {
                $summary['skipped']++;
                return;
            }
        }

        $rawPhone = mb_substr((string) ($row['phone'] ?? ''), 0, 30);
        $phone = self::normalizePhone($rawPhone, (string) $row['country']);
        $values = $this->templateValues($row, $config);
        $linkTemplate = trim((string) ($row['payment_link'] ?? '')) ?: trim((string) ($step['payment_link'] ?? ''));
        $paymentLink = $this->renderPaymentLink($linkTemplate, $values);
        $values['link_pagamento'] = $paymentLink;
        $message = self::renderTemplate((string) $step['message_template'], $values);
        $image = trim((string) ($step['image_url'] ?? ''));
        $payloadType = $image !== '' ? 'image' : 'text';
        $scheduledFor = $now->format('Y-m-d') . ' ' . substr((string) $step['send_time'], 0, 8);

        if (!$existing) {
            $logId = $this->db->insert(
                "INSERT INTO whatsapp_reminder_logs
                    (subscription_id,client_id,automation_step_id,reminder_type,reminder_number,due_date,
                     recipient_phone,rendered_message,payload_type,media_url,payment_link,scheduled_for,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'pending')",
                [
                    $row['subscription_id'],$row['client_id'],$stepId,$step['reminder_type'],$step['position'],
                    $row['next_billing_date'],$phone ?: $rawPhone,$message,$payloadType,$image ?: null,
                    $paymentLink ?: null,$scheduledFor,
                ]
            );
        } else {
            $logId = (int) $existing['id'];
            $this->db->query(
                "UPDATE whatsapp_reminder_logs
                 SET status='pending',recipient_phone=?,rendered_message=?,payload_type=?,media_url=?,
                     payment_link=?,scheduled_for=?,error_message=NULL
                 WHERE id=?",
                [$phone ?: $rawPhone,$message,$payloadType,$image ?: null,$paymentLink ?: null,$scheduledFor,$logId]
            );
        }

        if (!$phone) {
            $this->db->query(
                "UPDATE whatsapp_reminder_logs
                 SET status='skipped',error_message='Telefone ausente ou inválido',last_attempt_at=? WHERE id=?",
                [$now->format('Y-m-d H:i:s'),$logId]
            );
            $summary['skipped']++;
            return;
        }

        try {
            $response = $this->sendPayload($phone, $message, $image, $config);
            $messageId = (string) ($response['messageId'] ?? $response['id'] ?? $response['zaapId'] ?? '');
            $this->db->query(
                "UPDATE whatsapp_reminder_logs
                 SET status='sent',attempts=attempts+1,provider_message_id=?,provider_response=?,
                     error_message=NULL,last_attempt_at=?,sent_at=?
                 WHERE id=?",
                [
                    $messageId,json_encode($response, JSON_UNESCAPED_UNICODE),
                    $now->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s'),$logId,
                ]
            );
            $summary['sent']++;
        } catch (\Throwable $exception) {
            $this->db->query(
                "UPDATE whatsapp_reminder_logs
                 SET status='failed',attempts=attempts+1,error_message=?,last_attempt_at=? WHERE id=?",
                [mb_substr($exception->getMessage(), 0, 500),$now->format('Y-m-d H:i:s'),$logId]
            );
            $summary['failed']++;
        }
    }

    private function templateValues(array $row, array $config): array
    {
        $name = trim((string) $row['client']);
        $firstName = preg_split('/\s+/', $name)[0] ?? $name;
        $amount = max(0, ((float) $row['unit_price'] * (int) $row['quantity']) - (float) $row['discount']);
        $dayOffset = (int) $row['day_offset'];
        $days = abs($dayOffset);
        $now = $this->now($config);

        return [
            'nome' => $name,
            'primeiro_nome' => $firstName,
            'empresa_cliente' => (string) ($row['company'] ?? ''),
            'telefone' => (string) ($row['phone'] ?? ''),
            'pais' => $row['country'] === 'BR' ? 'Brasil' : 'Estados Unidos',
            'produto' => (string) $row['product'],
            'quantidade' => (string) (int) $row['quantity'],
            'data_hoje' => $now->format('d/m/Y'),
            'data_vencimento' => (new DateTimeImmutable($row['next_billing_date']))->format('d/m/Y'),
            'valor' => \money($amount, (string) $row['currency']),
            'moeda' => (string) $row['currency'],
            'dias_para_vencimento' => (string) ($dayOffset > 0 ? $days : 0),
            'dias_para_vencer' => (string) ($dayOffset > 0 ? $days : 0),
            'dias_atraso' => (string) ($dayOffset < 0 ? $days : 0),
            'dias_em_atraso' => (string) ($dayOffset < 0 ? $days : 0),
            'forma_pagamento' => (string) ($row['payment_method'] ?? ''),
            'link_pagamento' => '',
            'telefone_suporte' => $config['whatsapp_support_phone'],
            'id_cliente' => (string) $row['client_id'],
            'id_assinatura' => (string) $row['subscription_id'],
            'empresa' => (string) ($this->db->value("SELECT setting_value FROM settings WHERE setting_key='company_name'") ?: 'Nossa equipe'),
        ];
    }

    private function renderPaymentLink(string $template, array $values): string
    {
        if ($template === '') {
            return '';
        }

        return self::renderTemplate($template, $values);
    }

    private function sendPayload(string $phone, string $message, string $image, array $config): array
    {
        if ($image === '') {
            return $this->request('POST', 'send-text', ['phone' => $phone, 'message' => $message], $config);
        }

        return $this->request(
            'POST',
            'send-image',
            ['phone' => $phone, 'image' => $this->resolveImage($image), 'caption' => $message, 'viewOnce' => false],
            $config
        );
    }

    private function resolveImage(string $image): string
    {
        if (!str_starts_with($image, 'storage/reminders/')) {
            if (!preg_match('#^https?://#i', $image)) {
                throw new RuntimeException('A URL da imagem configurada é inválida.');
            }

            return $image;
        }

        $root = dirname(__DIR__, 2);
        $directory = realpath($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders');
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $image));
        if ($directory === false || $path === false
            || !str_starts_with(strtolower($path), strtolower($directory . DIRECTORY_SEPARATOR))
            || !is_file($path)) {
            throw new RuntimeException('A imagem enviada não está mais disponível no servidor.');
        }
        if (filesize($path) > 6 * 1024 * 1024) {
            throw new RuntimeException('A imagem ultrapassa o limite de 6 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            throw new RuntimeException('O formato da imagem não é compatível.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler a imagem configurada.');
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
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
            CURLOPT_TIMEOUT => 30,
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

    private function now(array $config): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($config['whatsapp_timezone']));
    }

    private function emptySummary(string $status): array
    {
        return [
            'status' => $status,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'duplicates' => 0,
            'limit_reached' => false,
        ];
    }

    private function finishRun(array $summary): array
    {
        $now = $this->now($this->config())->format('Y-m-d H:i:s');
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
