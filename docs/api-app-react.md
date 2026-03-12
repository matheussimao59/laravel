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

## O que ainda falta migrar

Estas partes do `app-react` ainda dependem de integracao externa e precisam de uma camada de servico propria no backend:

- login/logout do frontend ainda usa Supabase Auth
- funcoes de OAuth e sincronizacao do Mercado Livre
- funcoes de emissao/consulta de NF (`nf-emit`, `nf-status`)
- cache local em `localStorage` ainda mantido no frontend

## Proximo passo recomendado

Criar um cliente HTTP no `app-react` e migrar por modulo nesta ordem:

1. `DashboardPage`
2. `PricingPage` e `ProductsPage`
3. `CalendarPage`
4. `MercadoLivreSeparacaoPage`
5. `CapaAgendaPage`
6. `NotaFiscalPage`
