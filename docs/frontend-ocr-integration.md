# Guia de integração frontend para OCR do caderno

Este documento explica como o frontend deve consumir o fluxo de reconhecimento de escrita manual do backend.

## Objetivo

O backend já reconhece texto manuscrito no servidor a partir dos traços (`stroke_data`) enviados pelo cliente. O texto reconhecido é guardado em uma estrutura separada, sem misturar com o texto introduzido manualmente pelo utilizador.

## Regras principais

- O OCR não é feito a partir de `text_data`.
- O OCR é processado no servidor, a partir dos traços manuscritos.
- O resultado do OCR fica separado do texto digitado/introduzido pelo utilizador.
- O texto reconhecido é guardado em duas camadas:
  - `extracted_text`: valor principal e mais simples para mostrar no UI.
  - `ocr_data`: histórico estruturado de entradas OCR, com contexto.

## Onde encontrar os dados

Na entidade `Page` (página do caderno), o frontend deve considerar estas colunas:

- `stroke_data`: traços manuscritos recebidos do cliente.
- `text_data`: texto introduzido pelo utilizador (camada dedicada a texto manual).
- `extracted_text`: texto mais recente reconhecido pelo OCR.
- `ocr_data`: lista de entradas OCR com contexto.

### Importante

- Não usar `text_data` para mostrar OCR.
- Não tentar reconstruir OCR a partir de `text_data`.
- O frontend deve tratar `ocr_data` como a camada oficial de resultados OCR.

## Fluxo real do backend

### 1) O cliente envia os traços

Na sincronização de páginas, o frontend deve enviar a página com campos como:

```json
{
  "notebook_id": 12,
  "page_number": 3,
  "stroke_data": [
    {"points": [{"x": 10, "y": 20}, {"x": 30, "y": 50}]}
  ],
  "text_data": []
}
```

### 2) O backend grava a página e dispara OCR em fila

Quando chegam novos traços e ainda não existe OCR para a página, o backend:

- guarda a página;
- agenda um job assíncrono em fila (`ocr`);
- processa o OCR no servidor;
- guarda o resultado em `extracted_text` e `ocr_data`.

### 3) O OCR chega mais tarde

O processo é assíncrono. Isto significa que o frontend pode receber a página logo após o envio, mas o OCR pode aparecer alguns segundos ou minutos depois, dependendo da fila e da carga do servidor.

## Contrato de dados recomendado para o frontend

### Campo principal para UI

Use `page.extracted_text` como fonte principal para exibir o conteúdo reconhecido da página.

Exemplo:

```json
{
  "id": 45,
  "notebook_id": 12,
  "page_number": 3,
  "extracted_text": "Texto reconhecido pela máquina",
  "ocr_data": [
    {
      "id": "uuid",
      "type": "ocr",
      "text": "Texto reconhecido pela máquina",
      "engine": "tesseract",
      "language": "por",
      "created_at": "2026-07-28T05:00:00.000000Z",
      "context": {
        "subject": {"id": 1, "name": "Matemática"},
        "notebook": {"id": 12, "title": "Caderno A"},
        "page": {"id": 45, "number": 3}
      },
      "subject_id": 1,
      "notebook_id": 12,
      "page_id": 45,
      "page_number": 3
    }
  ],
  "text_data": []
}
```

### Quando usar cada campo

- `extracted_text`: mostrar o texto OCR atual na tela.
- `ocr_data`: mostrar histórico, detalhes, contexto, engine, idioma e metadados.
- `text_data`: mostrar conteúdo escrito manualmente pelo utilizador.

## Endpoints relevantes

### 1) Sincronizar páginas

- `POST /api/sync/pages/push`

Use este endpoint para enviar páginas com strokes e texto do utilizador.

Exemplo de payload enviado pelo frontend:

```json
{
  "pages": [
    {
      "notebook_id": 12,
      "page_number": 3,
      "stroke_data": [
        {"points": [{"x": 10, "y": 20}, {"x": 30, "y": 50}]}
      ],
      "text_data": [],
      "language": "por"
    }
  ]
}
```

Resposta esperada do backend após o processamento:

```json
{
  "message": "Páginas salvas.",
  "synced_pages": [
    {
      "client_id": null,
      "server_id": 45,
      "page_number": 3
    }
  ]
}
```

Nota: o OCR não aparece imediatamente nesta resposta. O resultado fica disponível mais tarde em `extracted_text` e `ocr_data` quando o job em fila terminar.

### 2) Páginas do caderno

- `GET /api/notebooks/{notebook_id}/pages`
- `POST /api/notebooks/{notebook_id}/pages`

Use estes endpoints para listar e persistir páginas. A resposta já deve incluir `extracted_text` e `ocr_data`.

Exemplo de resposta de `GET /api/notebooks/{notebook_id}/pages` com OCR já processado:

```json
{
  "data": [
    {
      "id": 45,
      "notebook_id": 12,
      "page_number": 3,
      "extracted_text": "Texto reconhecido pela máquina",
      "stroke_data": [
        {"points": [{"x": 10, "y": 20}, {"x": 30, "y": 50}]}
      ],
      "text_data": [],
      "ocr_data": [
        {
          "id": "b1d4f4cf-2b42-4f16-8d59-6a7803d34fc1",
          "type": "ocr",
          "text": "Texto reconhecido pela máquina",
          "engine": "tesseract",
          "language": "por",
          "created_at": "2026-07-28T05:00:00.000000Z",
          "context": {
            "subject": {"id": 1, "name": "Matemática"},
            "notebook": {"id": 12, "title": "Caderno A"},
            "page": {"id": 45, "number": 3}
          },
          "subject_id": 1,
          "notebook_id": 12,
          "page_id": 45,
          "page_number": 3
        }
      ],
      "created_at": "2026-07-28T04:55:10.000000Z",
      "updated_at": "2026-07-28T05:00:20.000000Z"
    }
  ],
  "current_page": 1,
  "per_page": 20,
  "total": 1
}
```

### 3) OCR direto por imagem (opcional)

- `POST /api/handwriting/recognize`

Este endpoint é útil para testes/diagnóstico. Se for chamado com `notebook_id` e `page_number`, ele também grava o resultado na página correspondente.

## Recomendação de UX para o frontend

1. Após o utilizador escrever na página, envie os strokes para o backend.
2. Mostre imediatamente a página localmente, sem bloquear a UI.
3. Exiba `extracted_text` assim que ele chegar.
4. Se precisar de mostrar o estado do OCR, trate isso como "em processamento" até o backend devolver o texto.
5. Quando o OCR terminar, faça refresh da página ou re-fetch do recurso.

## Ponto importante para a implementação

O backend ainda não publica um evento dedicado de “OCR concluído” para o frontend. Por isso, a estratégia recomendada é:

- reabrir/recarregar a página após a sincronização concluir; ou
- re-buscar a página após algum atraso curto.

## Modelo Flutter/Dart sugerido

Um exemplo simples de modelo para o frontend consumir os dados da página e do OCR:

```dart
class PageResponse {
  final int id;
  final int notebookId;
  final int pageNumber;
  final String? extractedText;
  final List<dynamic> strokeData;
  final List<dynamic> textData;
  final List<OcrEntry> ocrData;

  PageResponse({
    required this.id,
    required this.notebookId,
    required this.pageNumber,
    required this.extractedText,
    required this.strokeData,
    required this.textData,
    required this.ocrData,
  });

  factory PageResponse.fromJson(Map<String, dynamic> json) {
    return PageResponse(
      id: json['id'],
      notebookId: json['notebook_id'],
      pageNumber: json['page_number'],
      extractedText: json['extracted_text'],
      strokeData: json['stroke_data'] ?? [],
      textData: json['text_data'] ?? [],
      ocrData: (json['ocr_data'] ?? [])
          .map<OcrEntry>((item) => OcrEntry.fromJson(item))
          .toList(),
    );
  }
}

class OcrEntry {
  final String id;
  final String type;
  final String text;
  final String? engine;
  final String? language;
  final String? createdAt;
  final OcrContext? context;

  OcrEntry({
    required this.id,
    required this.type,
    required this.text,
    required this.engine,
    required this.language,
    required this.createdAt,
    required this.context,
  });

  factory OcrEntry.fromJson(Map<String, dynamic> json) {
    return OcrEntry(
      id: json['id'],
      type: json['type'],
      text: json['text'],
      engine: json['engine'],
      language: json['language'],
      createdAt: json['created_at'],
      context: json['context'] != null ? OcrContext.fromJson(json['context']) : null,
    );
  }
}

class OcrContext {
  final OcrSubject? subject;
  final OcrNotebook? notebook;
  final OcrPage? page;

  OcrContext({required this.subject, required this.notebook, required this.page});

  factory OcrContext.fromJson(Map<String, dynamic> json) {
    return OcrContext(
      subject: json['subject'] != null ? OcrSubject.fromJson(json['subject']) : null,
      notebook: json['notebook'] != null ? OcrNotebook.fromJson(json['notebook']) : null,
      page: json['page'] != null ? OcrPage.fromJson(json['page']) : null,
    );
  }
}

class OcrSubject {
  final int? id;
  final String? name;

  OcrSubject({required this.id, required this.name});

  factory OcrSubject.fromJson(Map<String, dynamic> json) {
    return OcrSubject(id: json['id'], name: json['name']);
  }
}

class OcrNotebook {
  final int? id;
  final String? title;

  OcrNotebook({required this.id, required this.title});

  factory OcrNotebook.fromJson(Map<String, dynamic> json) {
    return OcrNotebook(id: json['id'], title: json['title']);
  }
}

class OcrPage {
  final int? id;
  final int? number;

  OcrPage({required this.id, required this.number});

  factory OcrPage.fromJson(Map<String, dynamic> json) {
    return OcrPage(id: json['id'], number: json['number']);
  }
}
```

Exemplo de uso no frontend:

```dart
final page = PageResponse.fromJson(responseData);

final textoOcr = page.extractedText ?? '';
final historicoOcr = page.ocrData;
```

## Resumo curto para o frontend

- Não usar `text_data` para OCR.
- Mostrar OCR via `extracted_text`.
- Usar `ocr_data` para detalhes e contexto.
- Tratar OCR como processamento assíncrono.
- O texto do utilizador continua separado em `text_data`.
