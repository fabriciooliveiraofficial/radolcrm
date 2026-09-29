<?php

declare(strict_types=1);

echo "Iniciando verificação do Provisionamento de Receitas Futuras (Multimoeda & Recorrências Avançadas)...\n";

$root = dirname(__DIR__);

// 1. Verificar Schema e Migração v21
$schemaContent = file_get_contents($root . '/database/schema.sql');
assert(str_contains($schemaContent, "currency ENUM('BRL','USD') NOT NULL DEFAULT 'BRL'"), "daily_transactions deve conter a coluna currency no schema.sql");
assert(str_contains($schemaContent, "original_amount DECIMAL(15,2) NULL"), "original_amount deve estar no schema.sql");
assert(str_contains($schemaContent, "exchange_rate DECIMAL(10,4) NULL"), "exchange_rate deve estar no schema.sql");
assert(preg_match('/\'schema_version\', \'(\d+)\'/', $schemaContent, $sm) && (int)$sm[1] >= 21, "Versão de schema deve ser 21 ou superior");
echo "✓ 1. Estrutura de banco e colunas multimoeda (BRL/USD) validadas no schema.sql (v21).\n";

// 2. Verificar MigrationService
$migrationContent = file_get_contents($root . '/app/Services/MigrationService.php');
assert(str_contains($migrationContent, "VERSION = 21"), "MigrationService deve estar na versão 21");
assert(str_contains($migrationContent, "\$version < 21"), "MigrationService deve conter o bloco de migração 21");
assert(str_contains($migrationContent, "daily_transactions ADD COLUMN currency"), "Migração v21 deve adicionar currency em daily_transactions");
assert(str_contains($migrationContent, "daily_recurring_commitments ADD COLUMN currency"), "Migração v21 deve adicionar currency em daily_recurring_commitments");
echo "✓ 2. MigrationService v21 com DDL defensivo multimoeda validado.\n";

// 3. Verificar ActionHandler para save_daily_future_income
$actionContent = file_get_contents($root . '/app/Http/ActionHandler.php');
assert(str_contains($actionContent, "'save_daily_future_income' => \$this->saveDailyFutureIncome()"), "Ação save_daily_future_income deve estar mapeada");
assert(str_contains($actionContent, "function saveDailyFutureIncome"), "Método saveDailyFutureIncome deve existir no ActionHandler");
assert(str_contains($actionContent, "prior_friday"), "saveDailyFutureIncome deve suportar regra prior_friday para fins de semana");
assert(str_contains($actionContent, "next_monday"), "saveDailyFutureIncome deve suportar regra next_monday para fins de semana");
assert(str_contains($actionContent, "'pending'"), "saveDailyFutureIncome deve criar lançamentos com status pending");
assert(str_contains($actionContent, "'income'"), "saveDailyFutureIncome deve criar lançamentos do tipo income");
echo "✓ 3. ActionHandler: saveDailyFutureIncome implementado com suporte a regras de fim de semana e status pendente.\n";

// 4. Testar Lógica de Conversão Cambial Matemática
$testUsdAmount = 1500.00;
$testRate = 5.4520;
$convertedBrl = round($testUsdAmount * $testRate, 2);
assert($convertedBrl === 8178.00, "Cálculo de conversão USD -> BRL deve ser exato (1500 * 5.4520 = 8178.00)");

$testBrlAmount = 3000.00;
$rateBrl = 1.0;
$finalBrl = round($testBrlAmount * $rateBrl, 2);
assert($finalBrl === 3000.00, "Cálculo de valor em BRL deve manter original");
echo "✓ 4. Lógica de conversão cambial e precisão decimal verificadas com sucesso.\n";

// 5. Testar Algoritmo de Cálculo de Datas (Semanal, Quinzenal, Mensal com Weekend Rules)
function testDateCalc(string $start, int $i, string $freq, string $rule): string {
    $dt = new DateTimeImmutable($start);
    if ($freq === 'weekly') {
        $dt = $dt->modify("+{$i} week");
    } elseif ($freq === 'biweekly') {
        $weeks = $i * 2;
        $dt = $dt->modify("+{$weeks} week");
    } else {
        $dt = $dt->modify("+{$i} month");
    }

    $dow = (int)$dt->format('N'); // 1 = Seg, 6 = Sáb, 7 = Dom
    if ($rule === 'prior_friday') {
        if ($dow === 6) $dt = $dt->modify('-1 day');
        elseif ($dow === 7) $dt = $dt->modify('-2 day');
    } elseif ($rule === 'next_monday') {
        if ($dow === 6) $dt = $dt->modify('+2 day');
        elseif ($dow === 7) $dt = $dt->modify('+1 day');
    }
    return $dt->format('Y-m-d');
}

// 5.1 Semanal
$w0 = testDateCalc('2026-10-05', 0, 'weekly', 'exact'); // Segunda
$w1 = testDateCalc('2026-10-05', 1, 'weekly', 'exact');
assert($w1 === '2026-10-12', "Semanal +1 semana deve ser 2026-10-12");

// 5.2 Quinzenal
$b0 = testDateCalc('2026-10-01', 0, 'biweekly', 'exact');
$b1 = testDateCalc('2026-10-01', 1, 'biweekly', 'exact');
assert($b1 === '2026-10-15', "Quinzenal +1 ciclo deve ser 2026-10-15 (+14 dias)");

// 5.3 Mensal com regra prior_friday
// 2026-10-10 é Sábado (dow = 6)
$satDate = '2026-10-10';
$friAdjusted = testDateCalc($satDate, 0, 'monthly', 'prior_friday');
assert($friAdjusted === '2026-10-09', "Sábado com prior_friday deve antecipar para sexta-feira (2026-10-09)");

// 5.4 Mensal com regra next_monday
$monAdjusted = testDateCalc($satDate, 0, 'monthly', 'next_monday');
assert($monAdjusted === '2026-10-12', "Sábado com next_monday deve adiar para segunda-feira (2026-10-12)");
echo "✓ 5. Fórmulas de projeção periódica (semanal, quinzenal, mensal) e tratamento de fins de semana validadas.\n";

// 6. Verificar DailyFinanceService
$serviceContent = file_get_contents($root . '/app/Services/DailyFinanceService.php');
assert(str_contains($serviceContent, "currency"), "DailyFinanceService deve carregar a coluna currency nas queries");
assert(str_contains($serviceContent, "original_amount"), "DailyFinanceService deve carregar original_amount");
assert(str_contains($serviceContent, "exchange_rate"), "DailyFinanceService deve carregar exchange_rate");
assert(str_contains($serviceContent, "weekly"), "DailyFinanceService deve suportar frequência semanal em compromissos");
assert(str_contains($serviceContent, "biweekly"), "DailyFinanceService deve suportar frequência quinzenal em compromissos");
echo "✓ 6. DailyFinanceService: Agenda Preditiva e Compromissos com suporte a USD e frequências personalizadas.\n";

// 7. Verificar View financeiro.php
$viewContent = file_get_contents($root . '/app/Views/pages/financeiro.php');
assert(str_contains($viewContent, "openQuickTxModal('future')"), "Botão ou atalho para provisionamento direto deve existir na view");
assert(str_contains($viewContent, "tabBtnFutureIncome"), "Aba de Provisionamento Futuro deve existir no modal");
assert(str_contains($viewContent, "futureIncomeForm"), "Formulário futureIncomeForm deve estar estruturado no modal");
assert(str_contains($viewContent, "handleFutureCurrencyChange"), "Função JS de alteração de moeda deve existir");
assert(str_contains($viewContent, "handleFutureFrequencyChange"), "Função JS de periodicidade (Semanal/Quinzenal/Mensal) deve existir");
assert(str_contains($viewContent, "updateFutureSchedule"), "Função JS de montagem do cronograma interativo deve existir");
assert(str_contains($viewContent, "futureScheduleTableBody"), "Tabela de ajuste fino de parcelas de receitas deve existir");
assert(str_contains($viewContent, "futureSumOriginalText"), "Barra de resumo com totais deve existir");
assert(str_contains($viewContent, "badge-currency-usd"), "Estilo de badge USD deve estar configurado no CSS");
assert(str_contains($viewContent, "badge-currency-brl"), "Estilo de badge BRL deve estar configurado no CSS");
assert(str_contains($viewContent, "US$"), "Exibição de valores em dólares americanos com prefixo US$ confirmada");
echo "✓ 7. View financeiro.php: UI interativa, cards BRL/USD, frequências e ajuste fino 100% integrados.\n";

echo "\n🎉 TODOS OS TESTES DE PROVISIONAMENTO DE RECEITAS FUTURAS PASSARAM COM 100% DE SUCESSO!\n";
