# Sistema de Repricing para Mercado Livre

Este sistema implementa funcionalidades similares ao PriceBot para automatizar o repricing de produtos no Mercado Livre.

## Funcionalidades

- **Repricing Automático 24/7**: Ajusta preços baseado em concorrentes.
- **Cálculo de Margem Real**: Considera custos, frete e impostos.
- **Proteção de Margem**: Evita preços abaixo da margem mínima.
- **Dashboard**: Visualização de produtos, margens e histórico.
- **Histórico de Preços**: Rastreia mudanças manuais e automáticas.

## Instalação

1. Execute as migrações:
   ```bash
   php artisan migrate
   ```

2. Configure o access_token do Mercado Livre no modelo User (adicione coluna `mercado_livre_access_token`).

3. Agende o job:
   - O job `RepriceProducts` está agendado para rodar a cada hora em `bootstrap/app.php`.

4. Acesse `/mercado-livre` (logado) para o dashboard.

## Uso

- Adicione produtos via dashboard.
- O sistema buscará concorrentes automaticamente.
- Preços são ajustados periodicamente se `auto_reprice` estiver ativo.

## Notas

- Certifique-se de que o banco de dados está rodando.
- Teste em ambiente de staging antes de produção.
- Personalize a lógica de repricing em `MercadoLivreService::calculateReprice`.