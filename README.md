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

## Cotação automática

A aplicação consulta `USD-BRL` na AwesomeAPI. Sem chave, a resposta pública pode ter cache. Uma chave gratuita dá acesso sem esse cache e pode ser cadastrada em **Configurações → Câmbio**.

Há três camadas de segurança:

1. cotação recente salva no MySQL;
2. nova consulta à AwesomeAPI quando o cache local vence;
3. última cotação válida ou taxa manual se a API estiver indisponível.

Cada pagamento ou gasto em USD guarda a taxa usada e o valor convertido. Assim, movimentações antigas não mudam com a cotação atual.

### Cron opcional

O dashboard atualiza a cotação sob demanda. Para manter o histórico mesmo sem acessos, crie no hPanel uma tarefa a cada 10 minutos:

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

Na tela **Assinaturas**, o botão de cobranças vencidas cria os pagamentos pendentes de cada ciclo ainda não lançado e avança a próxima data. Ele apenas organiza as contas a receber: nenhuma cobrança bancária ou no cartão é disparada.

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
