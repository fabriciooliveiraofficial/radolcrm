<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use RuntimeException;

final class ExchangeRateService
{
    private const ENDPOINT = 'https://api.frankfurter.dev/v2/rate/USD/BRL';

    public function __construct(private readonly Database $db)
    {
    }

    public function current(bool $forceRefresh = false): array
    {
        $latest = $this->db->fetch(
            "SELECT * FROM exchange_rates WHERE base_currency = 'USD' AND quote_currency = 'BRL' AND source LIKE 'Frankfurter%' ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $cacheMinutes = max(60, (int) $this->setting('exchange_cache_minutes', '720'));

        // A idade do cache é baseada no momento da consulta, não no horário da
        // última negociação (que pode ser de sexta-feira durante o fim de semana).
        if (!$forceRefresh && $latest && strtotime($latest['created_at']) >= time() - ($cacheMinutes * 60)) {
            return $this->format($latest, false);
        }

        try {
            return $this->store($this->fetchRemote(), true);
        } catch (\Throwable $exception) {
            $fallback = $latest ?: $this->db->fetch(
                "SELECT * FROM exchange_rates WHERE base_currency='USD' AND quote_currency='BRL' ORDER BY created_at DESC,id DESC LIMIT 1"
            );
            if ($fallback) {
                $formatted = $this->format($fallback, false);
                $formatted['warning'] = 'Fonte diária indisponível; usando a última cotação salva.';
                return $formatted;
            }

            $manual = (float) $this->setting('manual_exchange_rate', '0');
            if ($manual <= 0) {
                throw new RuntimeException('Não foi possível obter a cotação e não há taxa manual configurada.', 0, $exception);
            }

            return [
                'bid' => $manual,
                'ask' => $manual,
                'source' => 'Taxa manual',
                'quoted_at' => date('Y-m-d H:i:s'),
                'fresh' => false,
                'warning' => 'Fonte diária indisponível; usando a taxa manual de segurança.',
            ];
        }
    }

    public function forDate(string $date, bool $forceRefresh = false): array
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('Data de resgate inválida para consultar o câmbio.');
        }
        $date = $parsed->format('Y-m-d');
        if (!$forceRefresh) {
            $stored = $this->db->fetch(
                "SELECT * FROM exchange_rates WHERE base_currency='USD' AND quote_currency='BRL' AND source LIKE 'Frankfurter%' AND DATE(quoted_at)=? ORDER BY id DESC LIMIT 1",
                [$date]
            );
            if ($stored) {
                return $this->format($stored, false);
            }
        }

        return $this->store($this->fetchRemote($date), true);
    }

    public function latestStored(): ?array
    {
        $latest = $this->db->fetch(
            "SELECT * FROM exchange_rates WHERE base_currency = 'USD' AND quote_currency = 'BRL' ORDER BY quoted_at DESC, id DESC LIMIT 1"
        );

        return $latest ? $this->format($latest, false) : null;
    }

    public function history(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->fetchAll(
            "SELECT bid, ask, source, quoted_at FROM exchange_rates WHERE base_currency = 'USD' AND quote_currency = 'BRL' ORDER BY quoted_at DESC, id DESC LIMIT {$limit}"
        );
    }

    private function fetchRemote(?string $date = null): array
    {
        $url = self::ENDPOINT . ($date ? '?date=' . rawurlencode($date) : '');
        $headers = ['Accept: application/json', 'User-Agent: RadolCRM/1.1'];

        $body = false;
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($body === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('Falha na API de câmbio: ' . ($error ?: 'HTTP ' . $status));
            }
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create(['http' => ['timeout' => 8, 'header' => implode("\r\n", $headers)]]);
            $body = @file_get_contents($url, false, $context);
        }

        if (!is_string($body) || $body === '') {
            throw new RuntimeException('O servidor não conseguiu consultar a API de câmbio.');
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $bid = (float) ($data['rate'] ?? 0);
        if ($bid <= 0) {
            throw new RuntimeException('A API retornou uma cotação inválida.');
        }
        $rateDate = (string) ($data['date'] ?? $date ?? date('Y-m-d'));

        return [
            'bid' => $bid,
            'ask' => $bid,
            'quoted_at' => $rateDate . ' 12:00:00',
            'source' => 'Frankfurter · diária',
            'base_currency' => 'USD',
            'quote_currency' => 'BRL',
        ];
    }

    private function store(array $rate, bool $fresh): array
    {
        $rate['id'] = $this->db->insert(
            'INSERT INTO exchange_rates (base_currency, quote_currency, bid, ask, source, quoted_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['USD', 'BRL', $rate['bid'], $rate['ask'], $rate['source'], $rate['quoted_at']]
        );

        return $this->format($rate, $fresh);
    }

    private function setting(string $key, string $default): string
    {
        $value = $this->db->value('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        return $value === false ? $default : (string) $value;
    }

    private function format(array $row, bool $fresh): array
    {
        return [
            'bid' => (float) $row['bid'],
            'ask' => (float) ($row['ask'] ?? $row['bid']),
            'source' => $row['source'],
            'quoted_at' => $row['quoted_at'],
            'fresh' => $fresh,
        ];
    }
}
