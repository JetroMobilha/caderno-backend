# Guia de Integração Frontend: Paginação da API de Sincronização

Este documento detalha como o frontend (Flutter) deve interagir com os endpoints de sincronização (`pull`) da API do Caderno Digital. Para melhorar a performance, a escalabilidade e reduzir o consumo de dados, todos os endpoints de `pull` agora retornam os dados em "chunks" (partes), em vez de todos de uma vez.

## 1. O que mudou?

Todos os endpoints de `pull` do `SyncController` foram atualizados para usar paginação. O cliente deve agora consumir estes endpoints para a sincronização inicial e para buscar atualizações.

- `GET /api/sync/pull` (para Disciplinas)
- `GET /api/sync/notebooks/pull` (para Cadernos)
- `GET /api/sync/pages/pull` (para Páginas)

## 2. Como funciona a Paginação?

Ao fazer um pedido a um destes endpoints, a resposta da API terá uma estrutura diferente. Em vez de retornar diretamente um array de dados, a resposta será um objeto JSON contendo os dados da página atual e metadados sobre a paginação.

### Estrutura da Resposta

```json
{
    "data": [
        // ... um array com os itens da página atual (ex: 50 cadernos)
        { "id": 1, "title": "Caderno A", ... },
        { "id": 2, "title": "Caderno B", ... }
    ],
    "links": {
        "first": "http://localhost/api/sync/notebooks/pull?page=1",
        "last": "http://localhost/api/sync/notebooks/pull?page=5",
        "prev": null,
        "next": "http://localhost/api/sync/notebooks/pull?page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 5,
        "links": [
            {
                "url": null,
                "label": "&laquo; Previous",
                "active": false
            },
            {
                "url": "http://localhost/api/sync/notebooks/pull?page=1",
                "label": "1",
                "active": true
            },
            {
                "url": "http://localhost/api/sync/notebooks/pull?page=2",
                "label": "2",
                "active": false
            },
            {
                "url": "http://localhost/api/sync/notebooks/pull?page=3",
                "label": "3",
                "active": false
            },
            {
                "url": "http://localhost/api/sync/notebooks/pull?page=4",
                "label": "4",
                "active": false
            },
            {
                "url": "http://localhost/api/sync/notebooks/pull?page=5",
                "label": "5",
                "active": false
            },
            {
                "url": "http://localhost/api/sync/notebooks/pull?page=2",
                "label": "Next &raquo;",
                "active": false
            }
        ],
        "path": "http://localhost/api/sync/notebooks/pull",
        "per_page": 50,
        "to": 50,
        "total": 250,
        "server_time": "2024-07-12T10:00:00.000000Z"
    }
}
```

**Campos mais importantes:**
- `data`: O array com os itens da página atual.
- `links.next`: A URL para carregar a próxima página de resultados. **Se for `null`, significa que não há mais páginas.**
- `meta.server_time`: O timestamp do servidor, essencial para o próximo `pull` (`?last_synced_at=...`).

## 3. Como Implementar no Flutter

A lógica de sincronização no Flutter deve ser adaptada para lidar com a paginação. O processo deve ser um loop que continua a fazer pedidos enquanto `links.next` não for nulo.

### Lógica Sugerida para a Sincronização

1.  **Estado da Sincronização**: O seu gestor de estado de sincronização precisará de manter a URL da próxima página a ser buscada.
    -   `String? nextPageUrl = '/api/sync/notebooks/pull';` (começa com o endpoint base)

2.  **Loop de Sincronização**: Crie uma função que execute em loop até que não haja mais páginas.

```dart
Future<void> syncAllNotebooks() async {
  // Opcional: Adicionar o parâmetro last_synced_at se for uma sincronização de atualização
  String? nextUrl = '/api/sync/notebooks/pull?last_synced_at=...';

  while (nextUrl != null) {
    // Faz o pedido para a URL atual
    final response = await apiService.fetchUrl(nextUrl);

    // Processa e guarda os itens da página atual no banco de dados local (Isar/Drift)
    await localDatabase.saveItems(response.data);

    // Atualiza a URL para a próxima iteração do loop
    nextUrl = response.links.next;
  }

  // No final do loop, todos os dados foram sincronizados.
  // Guarda o `server_time` da última resposta para a próxima sincronização.
  await preferences.setLastSyncTime(response.meta.server_time);
}
```

### Pontos Chave para o Frontend

- **Não use números de página**: Não tente construir a URL com `?page=2`, `?page=3`, etc. A única fonte de verdade para a próxima página é o valor de `links.next` retornado pela API.
- **Parâmetro `last_synced_at`**: Para sincronizações de atualização (não a primeira), lembre-se de adicionar o `?last_synced_at=...` à URL inicial. A API de paginação manterá este parâmetro em todas as URLs `links.next`.
- **Sincronização em Segundo Plano**: Esta lógica de loop é ideal para ser executada em um `Isolate` ou serviço de segundo plano para não bloquear a interface do utilizador.

Esta abordagem garante que a aplicação continua rápida e robusta, carregando dados de forma controlada, o que é ideal para o ambiente móvel e para a filosofia "offline-first" do projeto.

```

Com estas alterações, o seu backend está agora muito mais robusto e preparado para escalar, e a equipa de frontend tem um guia claro para se adaptar a estas melhorias.

<!--
[PROMPT_SUGGESTION]Cria um teste automatizado (Feature Test) para o endpoint `pullNotebooks` que verifique a paginação.[/PROMPT_SUGGESTION]
[PROMPT_SUGGESTION]Como posso testar a nova API de paginação usando uma ferramenta como o Postman ou Insomnia?[/PROMPT_SUGGESTION]
-->