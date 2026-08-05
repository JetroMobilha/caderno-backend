# Guia de Implementação (Flutter): Pesquisa Global

Este documento detalha como o agente Flutter deve implementar a funcionalidade de Pesquisa Global, que permite aos utilizadores pesquisar texto dentro do conteúdo manuscrito (OCR) de todas as suas páginas.

## 1. Visão Geral da Funcionalidade

O objetivo é criar uma barra de pesquisa na interface principal da aplicação. Quando um utilizador digita um termo e inicia a pesquisa, a aplicação deve fazer uma chamada à API do backend, que por sua vez irá procurar o termo na coluna `extracted_text` de todas as páginas acessíveis pelo utilizador.

Os resultados devem ser exibidos numa lista, onde cada item representa uma página correspondente. Tocar num item da lista deve navegar o utilizador diretamente para essa página dentro do seu respetivo caderno.

## 2. Interação com a API

A comunicação com o backend será feita através de um único endpoint.

### Endpoint

- **Método:** `GET`
- **URL:** `/api/search`
- **Autenticação:** Obrigatória (Bearer Token Sanctum)

### Parâmetros da Requisição (Query)

- `term` (string, obrigatório): O termo de pesquisa que o utilizador inseriu.
- `page` (int, opcional): O número da página de resultados, para paginação. O backend retornará 20 resultados por página.

**Exemplo de Chamada (usando o Dio):**

```dart
Future<List<SearchResult>> searchGlobal(String searchTerm, {int page = 1}) async {
  try {
    final response = await dio.get(
      '/api/search',
      queryParameters: {
        'term': searchTerm,
        'page': page,
      },
    );
    // Assumindo que a resposta tem uma chave 'data' com a lista de resultados
    return (response.data['data'] as List)
        .map((item) => SearchResult.fromJson(item))
        .toList();
  } on DioError catch (e) {
    // Tratar o erro (ex: 401 Unauthorized, 422 Unprocessable Entity)
    print(e);
    return [];
  }
}
```

### Formato da Resposta (JSON)

O backend retornará um objeto JSON paginado. A chave `data` conterá uma lista de objetos, onde cada objeto representa uma página que correspondeu à pesquisa.

**Exemplo de Resposta de Sucesso (200 OK):**
```json
{
  "data": [
    {
      "page_id": 101,
      "page_number": 5,
      "notebook_id": 23,
      "notebook_title": "Física Quântica",
      "subject_id": 4,
      "subject_name": "Ciências",
      "preview_text": "...um trecho do texto extraído onde a palavra 'onda' foi encontrada...",
      "score": 9.5
    },
    {
      "page_id": 254,
      "page_number": 12,
      "notebook_id": 45,
      "notebook_title": "História da Arte",
      "subject_id": 7,
      "subject_name": "Humanidades",
      "preview_text": "...a função de onda de Schrödinger descreve como o estado...",
      "score": 8.7
    }
  ],
  "links": {
    "first": "http://localhost/api/search?page=1",
    "last": "http://localhost/api/search?page=3",
    "prev": null,
    "next": "http://localhost/api/search?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "path": "http://localhost/api/search",
    "per_page": 20,
    "to": 20,
    "total": 55
  }
}
```

## 3. Modelo de Dados no Flutter (`SearchResult`)

É recomendado criar um modelo de dados no Flutter para mapear os resultados da pesquisa.

```dart
class SearchResult {
  final int pageId;
  final int pageNumber;
  final int notebookId;
  final String notebookTitle;
  final int subjectId;
  final String subjectName;
  final String previewText;
  final double score;

  SearchResult({
    required this.pageId,
    required this.pageNumber,
    required this.notebookId,
    required this.notebookTitle,
    required this.subjectId,
    required this.subjectName,
    required this.previewText,
    required this.score,
  });

  factory SearchResult.fromJson(Map<String, dynamic> json) {
    return SearchResult(
      pageId: json['page_id'],
      pageNumber: json['page_number'],
      notebookId: json['notebook_id'],
      notebookTitle: json['notebook_title'],
      subjectId: json['subject_id'],
      subjectName: json['subject_name'],
      previewText: json['preview_text'],
      score: (json['score'] as num).toDouble(),
    );
  }
}
```

## 4. Lógica da Interface (UI)

1.  **Input de Pesquisa:** Adicionar um `TextField` para o utilizador digitar o termo.
2.  **Ação de Pesquisa:** Ao submeter a pesquisa (ex: ícone de pesquisa ou tecla "Enter"), chamar o método `searchGlobal`.
3.  **Exibição de Resultados:** Usar um `ListView.builder` para renderizar a lista de `SearchResult`. Cada item da lista deve mostrar informações úteis como `notebookTitle`, `pageNumber` e `previewText`.
4.  **Navegação:** Ao tocar num item da lista, a aplicação deve navegar para a página correspondente. A navegação exigirá o `notebookId` e o `pageId` (ou `pageNumber`, dependendo da lógica de navegação existente).
5.  **Paginação (Scroll Infinito):** Para carregar mais resultados, monitorizar o scroll do `ListView`. Quando o utilizador chegar perto do final da lista e `meta.current_page < meta.last_page`, fazer uma nova chamada à API com o número da próxima página (`meta.current_page + 1`) e adicionar os novos resultados à lista existente.

Este guia fornece a base para a implementação da pesquisa global. O backend cuidará da lógica de busca complexa; a tarefa do agente Flutter é gerir a interface, as chamadas à API e a exibição dos resultados.
