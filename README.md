# Nexo Gestão

CRM financeiro para negócios recorrentes que vendem no Brasil e nos Estados Unidos. A aplicação reúne clientes, produtos, assinaturas, pagamentos, gastos, investimentos, caixa, relatórios e conversão USD/BRL.

## Requisitos

- PHP 8.1 ou superior com PDO MySQL, cURL e mbstring
- MySQL 8 ou MariaDB 10.5+
- Apache com `.htaccess` (padrão da hospedagem compartilhada Hostinger)
- HTTPS em produção

Não há build, Composer, Node.js ou processo residente. Isso permite publicar todos os arquivos diretamente em `public_html`.

## Instalação na Hostinger

1. No hPanel, crie um banco MySQL e guarde servidor, banco, usuário e senha.
2. Envie todo o conteúdo deste diretório para `public_html` pelo Gerenciador de Arquivos ou FTP.
3. Confira se o domínio usa PHP 8.1 ou superior e ative SSL.
4. Acesse `https://seu-dominio.com/install.php`.
5. Informe os dados do MySQL e crie o administrador.
6. Entre na plataforma. O instalador fica automaticamente bloqueado depois que `config/config.php` é criado.

Se o instalador não puder gravar a configuração, dê permissão de escrita temporária à pasta `config`, conclua a instalação e restaure a permissão recomendada pelo hPanel.

## Cotação diária automática

A aplicação consulta `USD-BRL` no Frankfurter, uma API open source de taxas institucionais diárias que não exige cadastro nem chave.

Há três camadas de segurança:

1. cotação recente salva no MySQL;
2. nova consulta ao Frankfurter quando o cache local vence;
3. última cotação válida ou taxa manual se a API estiver indisponível.

Cada pagamento em USD possui uma data de pagamento e uma data de resgate. A taxa diária da data do resgate, sua fonte e o valor convertido ficam gravados no lançamento. Assim, movimentações antigas não mudam com cotações futuras. A cotação efetivamente aplicada pelo banco ou plataforma também pode ser informada manualmente.

### Preços de produtos com câmbio

No cadastro de produtos, a formação do preço pode ser configurada de três formas:

- **Preços locais independentes:** BRL e USD são informados manualmente.
- **Cotado em real:** o preço em BRL é fixo e o equivalente em USD acompanha a cotação diária.
- **Cotado em dólar:** o preço em USD é fixo e o equivalente em BRL acompanha a cotação diária.

Os valores convertidos são usados em novas assinaturas e apresentados para conferência nas renovações. Contratos existentes não são reescritos silenciosamente: alterações de valor só entram na assinatura após a confirmação da renovação e ficam registradas no histórico.

### Cron opcional

O dashboard atualiza a cotação sob demanda. Para manter o histórico mesmo sem acessos, você pode criar no hPanel uma tarefa diária:

```text
php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/cron/update-exchange-rate.php SEU_CRON_SECRET
```

No hPanel, use o tipo **Custom/Personalizado** para poder passar o segredo como argumento. O segredo fica em `config/config.php`, na chave `app.cron_secret`. Ajuste o caminho conforme o exibido pelo hPanel e lembre que o agendamento da Hostinger usa UTC.

## Como os indicadores são calculados

- **Faturamento bruto:** pagamentos com status pago, convertidos pela taxa salva.
- **Receita líquida:** faturamento bruto menos taxas dos pagamentos.
- **Lucro líquido:** receita líquida − despesas − investimentos. Aportes, retiradas e ajustes avulsos alteram o caixa, mas não o lucro.
- **MRR:** valor mensal equivalente das assinaturas ativas; trimestrais, semestrais e anuais são proporcionalizadas.
- **Saldo de caixa:** saldo inicial + todos os pagamentos líquidos + entradas − gastos pagos − saídas.

Pagamentos pendentes aparecem no acompanhamento, mas só afetam resultado e caixa quando marcados como pagos.

### Filtros inteligentes

As listagens de clientes, produtos, assinaturas, pagamentos, gastos, investimentos e caixa pesquisam automaticamente enquanto o usuário digita. A busca consulta todas as páginas do banco e considera campos visíveis e complementares, como status, país, moeda, datas, valores, documentos, formas de pagamento e observações. Somente os resultados são atualizados, mantendo o foco no campo de pesquisa. Seletores e períodos também são aplicados sem botão de busca.

### Fluxo automático das assinaturas

1. Em **Assinaturas**, clique em **Gerar próximas cobranças**. O CRM abre uma conferência com as cobranças pendentes e as previstas nos próximos 45 dias.
2. Revise ou ajuste plano, moeda, quantidade, preço, desconto, valor recebido, taxa, data, forma de pagamento e referência externa. A tela informa se o pagamento foi antecipado, pontual ou atrasado e bloqueia valores diferentes do total devido.
3. Clique em **Confirmar e receber**. Em uma única transação, o CRM grava o pagamento como recebido, aplica a cotação diária aos valores em USD, renova a próxima data e atualiza as condições da assinatura quando houver mudança de plano.
4. Pagamento, renovação e alterações comerciais ficam disponíveis no botão **Histórico** de cada assinatura. A proteção contra duplicidade impede que a mesma renovação seja contabilizada duas vezes.

Pagamentos vinculados a assinaturas e confirmados diretamente no módulo **Pagamentos** também renovam automaticamente a próxima data. A data só avança após a confirmação como pago; apenas abrir a conferência não altera nenhum registro.

### Dashboard executivo

O dashboard combina indicadores financeiros com visão avançada da carteira: clientes ativos, inativos, leads, recorrentes e vencidos; score de gestão; taxa de recebimento em 90 dias; MRR, ARR, ARPA, ciclo de vida das assinaturas, pipeline de renovações, receita por produto e presença por país. O copiloto destaca automaticamente as ações prioritárias com links para as telas operacionais.

### Radar de vencimentos

A página **Assinaturas** possui um radar para atrasadas, vencimentos de hoje, amanhã, em dois dias e nos próximos sete dias. Os cartões funcionam como filtros rápidos e as linhas recebem cores, ícones e textos de urgência. Quando existem assinaturas que vencem no dia seguinte, um popup diário apresenta cliente, produto e valor; depois de dispensado, ele pode ser reaberto pelo botão de alertas do radar.

Esse fluxo organiza e confirma os recebimentos, mas não dispara uma cobrança bancária, PIX ou cartão. Uma integração com o processador de pagamentos seria necessária para capturar dinheiro automaticamente.

## Lembretes automáticos por WhatsApp

O painel **Lembretes WhatsApp** integra o CRM à [Z-API](https://developer.z-api.io/message/send-text) para enviar mensagens de texto antes e depois do vencimento. A configuração permite:

- ativar separadamente lembretes de assinaturas a vencer e vencidas;
- definir quantos dias antes ou depois iniciar os avisos;
- escolher o intervalo e o limite de mensagens por ciclo de cobrança;
- personalizar os dois modelos com variáveis como `{{primeiro_nome}}`, `{{produto}}`, `{{data_vencimento}}`, `{{valor}}` e `{{empresa}}`;
- testar o status da instância e executar o processamento manualmente;
- consultar mensagens enviadas, falhas e clientes sem telefone válido.

Informe o WhatsApp de cada cliente com DDI, DDD e número. O sistema completa automaticamente os códigos do Brasil e dos Estados Unidos quando o telefone local estiver completo. Assinaturas pagas, pausadas ou canceladas não recebem avisos, e uma chave única impede o mesmo lembrete de ser enviado duas vezes.

No painel da Z-API, copie o ID da instância, o token da instância e, se a segurança da conta estiver habilitada, o `Client-Token`. As credenciais ficam no banco de dados do servidor e não são devolvidas ao navegador depois de salvas. Consulte também a documentação oficial sobre o [Client-Token](https://developer.z-api.io/en/security/client-token) e o [status da instância](https://developer.z-api.io/en/instance/status).

Para automatizar, configure no hPanel um cron a cada 15 minutos:

```text
php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/cron/send-whatsapp-reminders.php SEU_CRON_SECRET
```

O cron pode rodar várias vezes: o horário definido no painel, o limite de tentativas e a prevenção de duplicidade são aplicados pelo CRM. Use um `SEU_CRON_SECRET` longo e igual ao valor `app.cron_secret` de `config/config.php`.

## Perfis de acesso

- **Administrador:** todos os lançamentos, configurações e usuários.
- **Gestor:** cria e edita dados operacionais e financeiros.
- **Visualizador:** consulta dashboard, cadastros e relatórios.

Alterações importantes são registradas na trilha de auditoria.

## Backup e segurança

- Configure backup diário do MySQL e dos arquivos no hPanel.
- Use HTTPS e senhas fortes para todos os usuários.
- Nunca compartilhe `config/config.php`; ele contém a senha do banco e o segredo do cron.
- Antes de atualizar arquivos em produção, faça um backup.

## Estrutura

```text
app/        núcleo, serviços, ações e views
assets/     CSS e JavaScript sem dependências externas
config/     configuração local protegida
cron/       atualização agendada do câmbio
database/   schema MySQL
storage/    arquivos internos protegidos
```
