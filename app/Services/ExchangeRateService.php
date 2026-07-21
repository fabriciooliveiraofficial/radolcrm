<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use RuntimeException;

final class ExchangeRateService
{
    private const ENDPOINT = 'https://economia.awesomeapi.com.br/json/last/USD-BRL';

    public function __construct(private readonly Database $db)
    {
    }

    public function current(bool $forceRefresh = false): array
    {
        $latest = $this->db->fetch(
            "SELECT * FROM exchange_rates WHERE base_currency = 'USD' AND quote_currency = 'BRL' ORDER BY quoted_at DESC, id DESC LIMIT 1"
        );
        $cacheMinutes = max(1, (int) $this->setting('exchange_cache_minutes', '10'));

        // A idade do cache é baseada no momento da consulta, não no horário da
        // última negociação (que pode ser de sexta-feira durante o fim de semana).
        if (!$forceRefresh && $latest && strtotime($latest['created_at']) >= time() - ($cacheMinutes * 60)) {
            return $this->format($latest, false);
        }

        try {
            $fresh = $this->fetchRemote();
            $id = $this->db->insert(
                'INSERT INTO exchange_rates (base_currency, quote_currency, bid, ask, source, quoted_at) VALUES (?, ?, ?, ?, ?, ?)',
                ['USD', 'BRL', $fresh['bid'], $fresh['ask'], 'AwesomeAPI', $fresh['quoted_at']]
            );
            $fresh['id'] = $id;
            $fresh['source'] = 'AwesomeAPI';
            $fresh['base_currency'] = 'USD';
            $fresh['quote_currency'] = 'BRL';

            return $this->format($fresh, true);
        } catch (\Throwable $exception) {
            if ($latest) {
                $formatted = $this->format($latest, false);
                $formatted['warning'] = 'API indisponível; usando a última cotação salva.';
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
                'warning' => 'API indisponível; usando a taxa manual de segurança.',
            ];
        }
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

    private function fetchRemote(): array
    {
        $apiKey = trim((string) $this->setting('awesome_api_key', ''));
        $headers = ['Accept: application/json', 'User-Agent: NexoGestao/1.0'];
        if ($apiKey !== '') {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        $body = false;
        if (function_exists('curl_init')) {
            $curl = curl_init(self::ENDPOINT);
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
            $body = @file_get_contents(self::ENDPOINT, false, $context);
        }

        if (!is_string($body) || $body === '') {
            throw new RuntimeException('O servidor não conseguiu consultar a API de câmbio.');
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $quote = $data['USDBRL'] ?? null;
        $bid = (float) ($quote['bid'] ?? 0);
        if ($bid <= 0) {
            throw new RuntimeException('A API retornou uma cotação inválida.');
        }

        $timestamp = (int) ($quote['timestamp'] ?? 0);
        $quotedAt = $timestamp > 0
            ? (new DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s')
            : date('Y-m-d H:i:s');

        return ['bid' => $bid, 'ask' => (float) ($quote['ask'] ?? $bid), 'quoted_at' => $quotedAt];
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
