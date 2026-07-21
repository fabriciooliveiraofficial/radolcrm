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
            'payments' => ['pagamentos', ['Data do pagamento','Data do resgate','Cliente','Descrição','Valor original','Moeda','Cotação','Fonte da cotação','Valor BRL','Taxas BRL','Líquido BRL','Status'], $this->db->fetchAll("SELECT p.payment_date,p.settlement_date,c.name,p.description,p.amount,p.currency,p.exchange_rate,p.exchange_rate_source,p.amount_brl,p.fee_brl,p.net_brl,p.status FROM payments p JOIN clients c ON c.id=p.client_id ORDER BY COALESCE(CASE WHEN p.currency='USD' THEN p.settlement_date ELSE p.payment_date END,p.payment_date) DESC")],
            'expenses' => ['gastos-investimentos', ['Data','Tipo','Categoria','Descrição','Fornecedor','Valor original','Moeda','Cotação','Valor BRL','Status'], $this->db->fetchAll('SELECT payment_date,type,category,description,supplier,amount,currency,exchange_rate,amount_brl,status FROM expenses ORDER BY payment_date DESC')],
            'subscriptions' => ['assinaturas', ['Cliente','Produto','Status','Moeda','Valor unitário','Quantidade','Desconto','Próxima cobrança'], $this->db->fetchAll('SELECT c.name client,p.name product,s.status,s.currency,s.unit_price,s.quantity,s.discount,s.next_billing_date FROM subscriptions s JOIN clients c ON c.id=s.client_id JOIN products p ON p.id=s.product_id ORDER BY c.name')],
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
