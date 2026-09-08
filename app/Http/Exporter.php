<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Database;

final class Exporter
{
    public function __construct(private readonly Database $db)
    {
    }

    public function download(string $type): never
    {
        [$filename, $headers, $rows] = match ($type) {
            'clients' => ['clientes', ['Nome','Empresa','E-mail','País','Moeda','Status'], $this->db->fetchAll('SELECT name,company,email,country,preferred_currency,status FROM clients ORDER BY name')],
            'payments' => ['pagamentos', ['Data do pagamento','Data do resgate','Cliente','Descrição','Meses renovados','Dias adicionais','Próxima cobrança','Valor-base','Desconto','Acréscimo','Ajuste manual','Valor final','Moeda','Cotação','Fonte da cotação','Valor BRL','Taxas BRL','Líquido BRL','Status'], $this->db->fetchAll("SELECT p.payment_date,p.settlement_date,c.name,p.description,p.renewal_months,p.renewal_days,p.renewal_end_date,p.base_amount,p.discount_amount,p.surcharge_amount,p.manual_adjustment_amount,p.amount,p.currency,p.exchange_rate,p.exchange_rate_source,p.amount_brl,p.fee_brl,p.net_brl,p.status FROM payments p JOIN clients c ON c.id=p.client_id ORDER BY COALESCE(CASE WHEN p.currency='USD' THEN p.settlement_date ELSE p.payment_date END,p.payment_date) DESC")],
            'expenses' => ['gastos-investimentos', ['Data','Tipo','Categoria','Descrição','Fornecedor','Valor original','Moeda','Cotação','Valor BRL','Status'], $this->db->fetchAll('SELECT payment_date,type,category,description,supplier,amount,currency,exchange_rate,amount_brl,status FROM expenses ORDER BY payment_date DESC')],
            'subscriptions' => ['assinaturas', ['Cliente','Produto','Status','Moeda','Valor unitário','Quantidade','Desconto','Próxima cobrança'], $this->db->fetchAll('SELECT c.name client,p.name product,s.status,s.currency,s.unit_price,s.quantity,s.discount,s.next_billing_date FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id ORDER BY c.name')],
            'statement' => (function () {
                [$from, $to] = period_dates();
                $buId = isset($_GET['bu']) && $_GET['bu'] !== '' ? (int) $_GET['bu'] : null;
                $search = (string) ($_GET['q'] ?? '');
                $typeFilter = (string) ($_GET['type'] ?? '');
                $catId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : null;
                $service = new \App\Services\FinanceService($this->db);
                $stmt = $service->detailedStatement($from, $to, $buId, $typeFilter, $search, $catId);
                $exportRows = [];
                foreach ($stmt['items'] as $tx) {
                    $exportRows[] = [
                        $tx['date'],
                        $tx['source_module'],
                        $tx['entity'],
                        $tx['description'],
                        $tx['category_name'],
                        $tx['bu_name'],
                        $tx['payment_method'],
                        $tx['direction'] === 'in' ? number_format($tx['amount_brl'], 2, ',', '.') : '0,00',
                        $tx['direction'] === 'out' ? number_format($tx['amount_brl'], 2, ',', '.') : '0,00',
                        number_format($tx['running_balance'], 2, ',', '.'),
                        $tx['status'] === 'paid' ? 'Pago / Concluído' : 'Pendente',
                    ];
                }
                return ['extrato-financeiro', ['Data','Módulo','Entidade / Favorecido','Descrição','Categoria','Unidade de Negócio','Método / Moeda','Entrada (R$)','Saída (R$)','Saldo Acumulado (R$)','Status'], $exportRows];
            })(),
            default => exit('Exportação inválida.'),
        };

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '-' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'wb');
        fputcsv($output, $headers, ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, array_values($row), ';', '"', '\\');
        }
        fclose($output);
        exit;
    }
}
