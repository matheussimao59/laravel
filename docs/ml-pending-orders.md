# Pedidos Mercado Livre pendentes e preenchimento de nome e idade

## Publicacao

- Publicar backend e React e executar `php artisan migrate --force` e `php artisan optimize:clear` (cria a tabela `ml_pending_orders`).
- Executar `php artisan test --filter=MlPendingOrdersTest` (ou a suite completa) em ambiente de testes.

## Fluxo

- O menu **Integracao > Pedidos Mercado Livre** leva a `/mercado-livre/pedidos-pendentes`.
- A sincronizacao consulta `/orders/search` com apenas os filtros ja comprovados em producao (seller, sort=desc, limit, offset, `order.date_created.from` dos ultimos 90 dias). Filtros extras como `tags.not` sao rejeitados por versoes da API e abortam a pagina toda.
- Cada pedido e conferido em `/shipments/{id}`; so fica `is_pending=true` com estado de envio confirmado (pending/handling/ready_to_ship) e pedido pago sem tags de entregue/enviado/cancelado. Sem confirmacao, o registro fica `is_pending=null` como "Verificar envio".
- A Mila le a conversa completa por paginas (`/messages/packs/{id}/sellers/{seller}?tag=post_sale&mark_as_read=false`): nunca envia mensagem e nunca marca o chat como lido.
- O preenchimento automatico exige: leitura completa, nenhuma mensagem pendente de moderacao, um unico nome e uma unica idade, e carrinho/itens sem ambiguidade. Qualquer divergencia marca `review` e exige revisao manual.
- Revisao manual salva nome, idade e observacao; a leitura automatica futura nunca substitui dados manuais.
- Na Impressao, a importacao consulta `POST /integracoes/mercado-livre/pending-orders/personalizations` pelo numero exato do pedido (ou carrinho confirmado). So preenche os campos quando `ready`; dados preenchidos ou editados no pedido nao sao sobrescritos.

## Erros e limites

- Falhas do ML nao voltam como 401 (o front desloga em 401): sai 422 com orientacao + resposta da API (ex. "Invalid filter", "invalid_token"), ou instrucao para reconectar a conta.
- 429 do ML volta 429 e o painel aplica espera de 60 segundos.
- Anexos e mensagens de outros participantes nao sao interpretados. Nenhum POST de mensagem e disparado por esta feature.
