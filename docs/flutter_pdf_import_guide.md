# Guia para o Agente Flutter: Importação de PDF para Caderno

Este guia detalha como implementar a funcionalidade de upload de arquivos PDF para criar um novo caderno no backend do Caderno.

## 1. Endpoint da API:

*   **Método:** `POST`
*   **URL:** `/api/notebooks/import-pdf`
*   **Autenticação:** Requer autenticação com `Sanctum` (o token de acesso deve ser enviado no cabeçalho `Authorization`).

## 2. Corpo da Requisição (`Request Body`):

A requisição HTTP deve ser do tipo `multipart/form-data`. Os seguintes campos são obrigatórios:

*   **`file` (File):** O arquivo PDF a ser carregado.
*   **`subject_id` (Integer):** O ID da disciplina (Subject) onde o novo caderno será criado.

### Exemplo Conceitual de Código Flutter para Requisição:

```dart
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:path/path.dart' as path;

Future<void> uploadPdf(String filePath, int subjectId, String authToken) async {
  var uri = Uri.parse('YOUR_BASE_URL/api/notebooks/import-pdf'); // Substitua YOUR_BASE_URL pelo URL base da sua API
  var request = http.MultipartRequest('POST', uri);

  // Adiciona o token de autenticação no cabeçalho
  request.headers['Authorization'] = 'Bearer $authToken';

  // Adiciona o arquivo PDF à requisição
  request.files.add(await http.MultipartFile.fromPath(
    'file',
    filePath,
    filename: path.basename(filePath),
    // contentType: MediaType('application', 'pdf'), // Opcional, será inferido
  ));

  // Adiciona o subject_id como um campo de texto
  request.fields['subject_id'] = subjectId.toString();

  try {
    var streamedResponse = await request.send();
    var response = await http.Response.fromStream(streamedResponse);

    if (response.statusCode == 201) {
      // Sucesso! Caderno criado.
      print('PDF enviado com sucesso! Resposta: ${response.body}');
      // Você pode fazer o parse do JSON da resposta aqui
    } else {
      // Erro
      print('Erro ao enviar PDF: ${response.statusCode} - ${response.body}');
    }
  } catch (e) {
    print('Exceção ao enviar PDF: $e');
  }
}

// Exemplo de uso (chame esta função com o caminho do arquivo PDF, subjectId e token)
// Para testes:
// String pdfPath = '/caminho/para/seu/documento.pdf'; // Ex: de um FilePicker
// int mySubjectId = 1;
// String userAuthToken = 'seu_token_jwt_sanctum';
// uploadPdf(pdfPath, mySubjectId, userAuthToken);
```

## 3. Resposta Esperada da API:

*   **Status `201 Created`:** Em caso de sucesso, o backend retornará um objeto JSON contendo os detalhes do novo `Notebook` criado, incluindo uma lista de `pages` associadas, cada uma com seu `background_image_path`.

    ```json
    {
        "id": 123,
        "title": "NomeDoSeuArquivoPdf",
        "subject_id": 1,
        "user_id": 456,
        "cover_type": "color", // Ou outro padrão
        "color": "#0F4C5C",    // Ou outro padrão
        "line_type": "ruled",  // Ou outro padrão
        "paper_size": "A4",    // Ou outro padrão
        "line_spacing": 28,    // Ou outro padrão
        "created_at": "2026-08-06T...",
        "updated_at": "2026-08-06T...",
        "pages": [
            {
                "id": 1,
                "notebook_id": 123,
                "page_number": 1,
                "background_image_path": "notebooks/123/backgrounds/page_1.png",
                "paper_size": null, // Atualmente null, veja Considerações
                "stroke_data": null,
                "created_at": "2026-08-06T...",
                "updated_at": "2026-08-06T..."
            },
            // ... mais objetos de página para cada página do PDF
        ]
    }
    ```

*   **Status `422 Unprocessable Entity`:** Erros de validação (ex: arquivo não é PDF, `subject_id` inválido ou ausente, tamanho máximo excedido). A resposta conterá os detalhes do erro de validação.

*   **Status `500 Internal Server Error`:** Erros inesperados no servidor.

*   **Status `501 Not Implemented`:** Se as dependências `Ghostscript` ou `Imagick` não estiverem instaladas ou configuradas corretamente no servidor, a API retornará um erro específico com esta mensagem.

## 4. Acesso às Imagens de Fundo das Páginas:

*   Após a importação, o `background_image_path` retornado para cada página é um caminho relativo (ex: `notebooks/123/backgrounds/page_1.png`).
*   Para exibir a imagem no frontend Flutter, você precisará construir a URL completa combinando o URL base da sua API com `'/storage/'` e o `background_image_path`.
    *   **Exemplo:** Se o URL base da sua API for `https://api.seuservidor.com`, a URL da imagem seria `https://api.seuservidor.com/storage/notebooks/123/backgrounds/page_1.png`.
*   **Importante:** Para que as imagens sejam acessíveis via URL `/storage/`, o comando `php artisan storage:link` deve ser executado no servidor. Isso cria um link simbólico da pasta `public/storage` para `storage/app/public`.

## 5. Considerações:

*   **Processamento Assíncrono:** Para arquivos PDF muito grandes, o processamento pode demorar e exceder o tempo limite de uma requisição HTTP. Uma melhoria futura para o backend seria implementar o processamento em background (usando jobs/queues), retornando imediatamente um status "processando" para o Flutter e notificando-o quando o caderno estiver pronto.
*   **`paper_size`:** Atualmente, o campo `paper_size` é retornado como `null`. Se for necessário ter o tamanho exato do papel de cada página PDF, uma futura implementação no backend pode extrair essa informação.

---
