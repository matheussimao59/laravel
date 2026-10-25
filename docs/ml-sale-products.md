# Produtos vendidos no Mercado Livre e custos locais

## Publicacao

- Publicar backend e React e executar `php artisan migrate --force` e `php artisan optimize:clear`.
- Executar `php artisan test --filter=MercadoLivreSaleProducts` em ambiente de testes.

## Fluxo

- O menu **Integracao > Produtos Mercado Livre** leva a `/mercado-livre/produtos-custos`.
- Vendas pagas reconhecidas na sincronizacao do painel e na varredura do bot criam a ficha local uma unica vez por usuario, vendedor, anuncio e variacao.
- A importacao acompanha a sincronizacao existente: nao foi criado um webhook nem um servico novo para sincronizar com o painel fechado.
- Tambem e possivel importar vendas de um intervalo de datas no novo menu (ate dez paginas por consulta; divida periodos com muitas vendas).
- A ficha usa o mesmo cadastro/precificacao de **Cadastros > Produtos**, na categoria Mercado Livre, incluindo materia-prima, quantidades, custos unitarios, mao de obra, fixos, outros custos e simulacao de taxas.
- As fichas importadas nao sao ativadas automaticamente para vendas no PDV e nao movimentam estoque.
- A primeira foto e o preco da venda entram com o pedido. Fotos completas, preco atual e descricao sao consultados ao abrir a ficha ou clicar em **Atualizar dados ML**. Uma falha nessa consulta nao remove os dados nem custos locais.
- Custos sao por unidade vendida no anuncio. Para kits, cadastrar o custo de todo o kit. Nao inferimos a quantidade do kit pelo titulo.

## Preservacao dos dados

- Os custos, materiais, taxas e preco planejado local nao sao sobrescritos por novas vendas ou atualizacoes dos dados do anuncio.
- Nenhum endpoint desta funcionalidade altera preco, anuncio ou estoque no Mercado Livre; nao ativa a rotina legada de repricificacao.
- A foto/descricao importada fica separada dos dados editados localmente.
- Salvar a precificacao confirma o custo. Um produto ainda nao precificado nao e tratado como tendo custo zero conhecido.
- Custo total zerado exige confirmacao explicita (`confirm_zero_cost`); sem ela, permanece pendente.
- O painel usa os custos atuais por ID de anuncio e variacao, multiplicados pela quantidade de cada item vendido. Vinculos manuais antigos continuam tendo prioridade.
- Em pedidos com varios produtos, o custo automatico fica pendente enquanto algum item nao tiver custo confirmado. Nao aproximamos o custo de um item desconhecido pelo nome de outro.
- Os valores de custo sao atuais, nao um historico imutavel por venda. Alterar a materia-prima pode alterar a analise de vendas anteriores na proxima sincronizacao.
