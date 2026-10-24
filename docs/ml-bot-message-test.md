# Teste assistido de mensagens do Mercado Livre

## Publicacao

1. Publicar as alteracoes da API e do React.
2. No backend, executar `php artisan migrate --force` e `php artisan optimize:clear`.
3. Antes de testar em producao, executar `php artisan test --filter=MercadoLivreBot` e `php artisan test --filter=MercadoLivreMessageOptions` em ambiente de testes.

## Como testar

1. Abrir **Mercado Livre > Bot**, em uma compra elegivel que ainda nao recebeu uma tentativa bloqueada.
2. Clicar em **Preparar teste de envio**. Esta etapa so consulta a conversa, os motivos e os limites; nao envia mensagem e nao marca o chat como lido.
3. Selecionar **Outros motivos**, se estiver habilitado, e revisar o texto. A edicao vale apenas para esta compra, sem alterar o grupo.
4. Se selecionar **Solicitar dados de personalizacao**, somente os IDs e textos oficiais retornados pela API sao utilizados. Nao e possivel editar esse modelo.
5. Conferir a compra e clicar em **Enviar uma vez ao comprador**. Este e um envio real. Nao ha envio em lote nem rotina automatica habilitada nesta fase.
6. Conferir a mensagem no Mercado Livre. Uma aceitacao inicial da API nao garante que a plataforma nao aplique moderacao posteriormente.

## Protecoes

- As permissoes sao consultadas novamente antes do POST. Ausencia de motivo ou limite desconhecido/zerado impede o envio.
- Nao ha troca automatica de um motivo recusado por outro, simulacao de cliques nem tentativa de contornar moderacao.
- O backend reserva o envio por compra/carrinho no banco para impedir dois envios concorrentes pelo bot.
- Moderacao, recusa apos tentativa ou resultado incerto ficam registrados em `ml_bot_orders.send_status` e `send_reason`. Atualizar a pagina ou marcar/desmarcar "Nao" nao libera outro envio.
- O chat tambem e consultado para reconhecer mensagens moderadas anteriores. Elas nao contam como envio bem-sucedido na varredura.
- Compras bloqueadas exigem verificacao manual no Mercado Livre. Nao existe botao para apagar a protecao e repetir a mensagem.

Documentacao oficial: https://developers.mercadolivre.com.br/pt_br/motivos-para-se-comunicar
