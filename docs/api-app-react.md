# API do app-react

Este backend Laravel agora cobre a base de dados interna usada pelo `app-react`, substituindo o acesso direto a tabelas do Supabase nos modulos locais do sistema.

## Recursos disponiveis

- `GET|PUT|DELETE /api/settings/{id}`
  Configuracoes genericas antes salvas em `app_settings`.
- `GET /api/settings`
  Lista configuracoes por `ids` ou `prefix`.
- `GET|POST|PUT|PATCH|DELETE /api/pricing/materials`
  Biblioteca de materiais.
- `GET|POST|PUT|PATCH|DELETE /api/pricing/products`
  Produtos precificados.
- `GET|POST|PUT|PATCH|DELETE /api/calendar/orders`
  Artes de calendario.
- `GET|PUT /api/fiscal/settings`
  Configuracao fiscal por usuario.
- `GET|POST|PATCH|DELETE /api/fiscal/documents`
  Documentos fiscais emitidos por pedido.
- `GET /api/financial/dashboard`
  Resumo financeiro.
- `GET|POST|PUT|PATCH|DELETE /api/financial/categories`
  Categorias financeiras.
- `POST /api/financial/accounts`
  Criacao de contas.
- `POST /api/financial/transactions`
  Criacao de lancamentos.
- `GET /api/shipping/orders`
  Lista de pedidos de envio.
- `POST /api/shipping/orders/import`
  Importacao com merge por `import_key`.
- `GET /api/shipping/orders/scan`
  Busca por rastreio/pedido.
- `PATCH /api/shipping/orders/{id}`
  Atualizacao pontual de pedido e `row_raw`.
- `DELETE /api/shipping/orders/{id}`
  Exclusao individual.
- `POST /api/shipping/orders/bulk-delete`
  Exclusao em lote por `ids` ou `import_keys`.
- `DELETE /api/shipping/orders/by-date`
  Exclusao por data de envio.
- `GET|POST|PUT|PATCH|DELETE /api/cover-agenda`
  Capas de agenda.
- `PATCH /api/cover-agenda/{id}/printed`
  Marca impressao.
- `POST /api/integrations/mercado-livre/oauth/token`
  Troca do codigo OAuth por `access_token`.
- `POST /api/integrations/mercado-livre/sync`
  Sincronizacao de vendedor e pedidos do Mercado Livre.
- `POST /api/integrations/mercado-livre/customization`
  Envio de mensagem de personalizacao para pedido/pacote.
- `POST /api/integrations/fiscal/emit`
  Emissao de NF via provedor externo.
- `POST /api/integrations/fiscal/status`
  Consulta de status de NF emitida.

## Banco de dados

As migrations novas criam as tabelas dos modulos internos:

- `personal_access_tokens`
- `app_settings`
- `pricing_materials`
- `pricing_products`
- `calendar_orders`
- `fiscal_settings`
- `fiscal_documents`
- `financial_accounts`
- `financial_categories`
- `financial_transactions`
- `shipping_orders`
- `cover_agenda_items`
- `app_files`

Tambem foi adicionada a compatibilidade do `users` com `role` e `is_active`.

## Integracoes externas

As integracoes externas usadas pelo `app-react-api` agora tambem passam pelo backend Laravel:

- OAuth do Mercado Livre
- sincronizacao de pedidos Mercado Livre
- envio de mensagem de personalizacao
- emissao de NF
- consulta de status da NF

O frontend ainda mantem apenas cache local em `localStorage` para melhorar carregamento e reduzir novas sincronizacoes.

## Status atual do frontend

O `app-react-api` ja usa esta API Laravel para:

- autenticacao principal com Sanctum (`/api/auth/login`, `/api/auth/register`, `/api/auth/me`, `/api/auth/logout`)
- modulos internos de configuracao, precificacao, pedidos, calendario, shipping, capa agenda e fiscal
- integracoes Mercado Livre e fiscal consumindo endpoints Laravel

Sobrou apenas o arquivo cliente `src/lib/supabase.ts`, que pode ser removido quando nao houver mais uso em nenhuma tela.

## Variaveis de ambiente do backend

Defina no `.env` do Laravel as credenciais das integracoes:

- `ML_CLIENT_ID`
- `ML_CLIENT_SECRET`
- `ML_BASE_URL` opcional
- `NFE_PROVIDER_TOKEN`
- `NFE_PROVIDER_BASE_URL`
- `NFE_ISSUE_PATH`
- `NFE_STATUS_PATH_TEMPLATE`

## Proximo passo recomendado

Fechar a operacao em ambiente real:

1. configurar as variaveis das integracoes no `.env` do Laravel
2. publicar a API atualizada no servidor
3. apontar `VITE_API_BASE_URL` do frontend para a API publicada
4. remover `src/lib/supabase.ts` se ele nao for mais usado
