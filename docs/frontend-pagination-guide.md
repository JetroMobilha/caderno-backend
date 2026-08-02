# Guia de Integração Frontend: Paginação da API

Este documento detalha como o frontend (Flutter) deve interagir com os endpoints paginados da API do Caderno Digital. Para melhorar a performance e reduzir o consumo de dados, os endpoints que listam `Disciplinas`, `Cadernos` e `Páginas` agora retornam os dados em "chunks" (partes), em vez de todos de uma vez.

## 1. O que mudou?

Os seguintes endpoints foram atualizados para usar paginação:

- `GET /api/subjects`
- `GET /api/subjects/{subject_id}/notebooks`
- `GET /api/notebooks/{notebook_id}/pages`

## 2. Como funciona a Paginação?

Ao fazer um pedido a um destes endpoints, a resposta da API terá uma estrutura diferente. Em vez de retornar diretamente um array de dados, a resposta será um objeto JSON contendo os dados da página atual e metadados sobre a paginação.

### Estrutura da Resposta

```json
{
  "data": [
    // ... um array com os itens da página atual (ex: 15 cadernos)
    { "id": 1, "title": "Caderno A", ... },
    { "id": 2, "title": "Caderno B", ... }
  ],
  "links": {
    "first": "http://localhost/api/notebooks?page=1",
    "last": "http://localhost/api/notebooks?page=5",
    "prev": null,
    "next": "http://localhost/api/notebooks?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "http://localhost/api/notebooks",
    "per_page": 15,
    "to": 15,
    "total": 75
  }
}
```

**Campos mais importantes:**
- `data`: O array com os itens da página atual.
- `links.next`: A URL para carregar a próxima página de resultados. Se for `null`, significa que não há mais páginas.
- `meta.current_page`: O número da página atual.
- `meta.last_page`: O número total de páginas.
- `meta.total`: O número total de itens em todas as páginas.

## 3. Como Implementar no Flutter (Scroll Infinito)

A melhor forma de consumir estes dados no frontend é implementar uma lista com "scroll infinito". O utilizador começa a ver os primeiros itens e, à medida que rola para o final da lista, o aplicativo faz um novo pedido à API para carregar a página seguinte e adiciona os novos itens ao final da lista.

### Lógica Sugerida para o Frontend

1.  **Estado do Widget**: O seu widget (ou gestor de estado) precisará de manter:
    -   Uma lista com os itens já carregados (ex: `List<Notebook> notebooks`).
    -   O número da página atual (ex: `int currentPage = 1`).
    -   Um booleano para saber se há mais páginas para carregar (ex: `bool hasMorePages = true`).
    -   Um booleano para evitar pedidos duplicados enquanto um já está em andamento (ex: `bool isLoading = false`).

2.  **Pedido Inicial**: Ao carregar a tela pela primeira vez, faça um pedido para a página 1.
    -   `GET /api/subjects?page=1`

3.  **Processar a Resposta**:
    -   Adicione os itens de `response.data` à sua lista local.
    -   Atualize `currentPage` com o valor de `response.meta.current_page`.
    -   Verifique se `response.links.next` é `null`. Se for, mude `hasMorePages` para `false`.

4.  **Lógica do Scroll**:
    -   Use um `ScrollController` na sua `ListView` ou `GridView`.
    -   Adicione um listener ao `ScrollController`.
    -   No listener, verifique se o utilizador chegou perto do final da lista (ex: `scrollController.position.pixels >= scrollController.position.maxScrollExtent - 200`).
    -   Se chegou ao final, e se `isLoading` for `false` e `hasMorePages` for `true`, então chame a função para carregar a próxima página.

### Exemplo de Função para Carregar Mais Itens

```dart
Future<void> fetchMoreItems() async {
  if (isLoading || !hasMorePages) return;

  setState(() {
    isLoading = true;
  });

  // A próxima página a ser pedida é a página atual + 1
  final nextPage = currentPage + 1;

  // Faça o pedido à API para a próxima página
  final response = await apiService.fetchNotebooks(page: nextPage);

  // Adicione os novos itens à lista existente
  setState(() {
    notebooks.addAll(response.data);
    currentPage = response.meta.current_page;
    hasMorePages = response.links.next != null;
    isLoading = false;
  });
}
```

Esta abordagem garante que a aplicação continua rápida e responsiva, carregando dados apenas quando necessário, o que é ideal para o ambiente móvel e para a filosofia "offline-first" do projeto.