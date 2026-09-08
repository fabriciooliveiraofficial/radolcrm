<?php

declare(strict_types=1);

// Corrige a "Data do Lançamento" de compras parceladas no cartão de crédito
// que foram gravadas com o bug em que cada parcela tinha a data avançada em
// N meses (igual ao vencimento da fatura), em vez de manter a data real da
// compra. A correção de código (ActionHandler::registerDailyTransaction) já
// impede que isso aconteça em novos lançamentos; este script conserta os
// registros já salvos com o problema.
//
// COMO USAR (pelo navegador, sem precisar de SSH):
//   1. Envie este arquivo para a pasta "scripts/" do site (mesmo lugar do
//      resto do projeto).
//   2. Acesse pelo navegador (troque SEU-TOKEN pelo valor abaixo):
//        https://seudominio.com/scripts/fix_installment_transaction_dates.php?token=SEU-TOKEN
//      Isso só MOSTRA o que seria alterado (modo simulação), sem tocar no banco.
//   3. Depois de conferir, acesse de novo acrescentando &apply=1:
//        https://seudominio.com/scripts/fix_installment_transaction_dates.php?token=SEU-TOKEN&apply=1
//      Aí sim ele aplica a correção no banco.
//   4. IMPORTANTE: depois de usar, apague este arquivo do servidor (ele fica
//      acessível por qualquer pessoa que souber a URL + token).

const ACCESS_TOKEN = '9068f25314f8e854a59725cbee1f76387be06883eee0c8a0';

header('Content-Type: text/plain; charset=utf-8');

$providedToken = (string) ($_GET['token'] ?? '');
if (!hash_equals(ACCESS_TOKEN, $providedToken)) {
    http_response_code(403);
    echo "Acesso negado. Informe ?token=SEU-TOKEN na URL.\n";
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

/** @var \App\Core\Database $db */

$apply = (($_GET['apply'] ?? '') === '1');

$rows = $db->fetchAll(
    "SELECT t.id, t.card_id, t.payee_id, t.payee_name, t.description, t.installment_number,
            t.total_installments, t.transaction_date, cc.name card_name
     FROM daily_transactions t
     LEFT JOIN daily_credit_cards cc ON cc.id = t.card_id
     WHERE t.card_id IS NOT NULL AND t.total_installments > 1
     ORDER BY t.card_id, t.payee_id, t.description, t.installment_number"
);

$groups = [];
foreach ($rows as $row) {
    $baseDescription = preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', (string) $row['description']);
    $key = implode('|', [
        $row['card_id'],
        $row['payee_id'] ?? $row['payee_name'],
        $baseDescription,
        $row['total_installments'],
    ]);
    $groups[$key][] = $row;
}

$totalGroupsFixed = 0;
$totalRowsFixed = 0;

echo $apply ? "MODO: APLICANDO CORREÇÃO\n\n" : "MODO: SIMULAÇÃO (nada será alterado)\n\n";

foreach ($groups as $groupRows) {
    $totalInstallments = (int) $groupRows[0]['total_installments'];

    // Grupo incompleto (ex.: uma parcela foi excluída) — não mexe, para não arriscar.
    if (count($groupRows) !== $totalInstallments) {
        continue;
    }

    usort($groupRows, static fn(array $a, array $b): int => $a['installment_number'] <=> $b['installment_number']);

    $first = $groupRows[0];
    if ((int) $first['installment_number'] !== 1) {
        continue;
    }
    $baseDate = new DateTimeImmutable((string) $first['transaction_date']);

    // Confirma a assinatura exata do bug: a data da parcela k está sempre
    // (k-1) meses à frente da data da parcela 1.
    $matchesBugSignature = true;
    foreach ($groupRows as $row) {
        $k = (int) $row['installment_number'];
        $rowDate = new DateTimeImmutable((string) $row['transaction_date']);
        $monthsDiff = ((int) $rowDate->format('Y') - (int) $baseDate->format('Y')) * 12
            + ((int) $rowDate->format('n') - (int) $baseDate->format('n'));
        if ($monthsDiff !== ($k - 1)) {
            $matchesBugSignature = false;
            break;
        }
    }
    if (!$matchesBugSignature) {
        continue;
    }

    $needsFix = false;
    foreach ($groupRows as $row) {
        if ($row['transaction_date'] !== $first['transaction_date']) {
            $needsFix = true;
            break;
        }
    }
    if (!$needsFix) {
        continue;
    }

    $totalGroupsFixed++;
    echo sprintf(
        "[%s] Cartão: %s | Favorecido: %s | Descrição: %s | %dx\n",
        $apply ? 'CORRIGINDO' : 'ENCONTRADO',
        $first['card_name'] ?? '—',
        $first['payee_name'],
        preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', (string) $first['description']),
        $totalInstallments
    );

    foreach ($groupRows as $row) {
        if ($row['transaction_date'] === $first['transaction_date']) {
            continue;
        }
        echo sprintf(
            "   parcela %d/%d (id=%d): %s -> %s\n",
            $row['installment_number'],
            $totalInstallments,
            $row['id'],
            $row['transaction_date'],
            $first['transaction_date']
        );
        $totalRowsFixed++;

        if ($apply) {
            $db->execute(
                "UPDATE daily_transactions SET transaction_date = ? WHERE id = ?",
                [$first['transaction_date'], $row['id']]
            );
        }
    }
    echo "\n";
}

echo "\n";
echo sprintf("Grupos afetados: %d | Lançamentos corrigidos: %d\n", $totalGroupsFixed, $totalRowsFixed);
echo $apply
    ? "Alterações aplicadas no banco de dados.\n"
    : "Modo SIMULAÇÃO — nenhuma alteração foi feita. Adicione &apply=1 na URL para aplicar de verdade.\n";
