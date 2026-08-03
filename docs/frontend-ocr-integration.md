# Guia de Integração Frontend para Reconhecimento de Escrita (OCR)

Este documento detalha como o frontend deve interagir com o fluxo de reconhecimento de escrita do backend.

## Visão Geral

O backend realiza o reconhecimento de escrita (OCR) a partir dos dados de traços (`stroke_data`) que o cliente envia. O texto extraído é armazenado de forma independente do texto que o utilizador digita manualmente.

## Princípios Fundamentais

- O OCR é um **serviço automático e interno do servidor**. O frontend não invoca um endpoint de OCR diretamente.
- O processo é acionado **automaticamente** quando uma página com novos `stroke_data` é sincronizada através do endpoint `POST /api/sync/pages/push`.
- O resultado do OCR é assíncrono e não modifica o campo `text_data` do utilizador.
- O texto reconhecido é disponibilizado em duas camadas na entidade `Page`:
  - `extracted_text`: O texto mais recente e simples, ideal para exibição na UI.
  - `ocr_data`: Um histórico estruturado com metadados detalhados sobre cada operação de OCR.

## Estrutura de Dados da Página (`Page`)

O frontend deve observar os seguintes campos no modelo `Page`:

- `stroke_data`: Os traços da escrita manual (enviados pelo cliente).
- `text_data`: O conteúdo de texto digitado pelo utilizador.
- `extracted_text`: O resultado do último OCR.
- `ocr_data`: O histórico completo de resultados do OCR.

**Importante**: `text_data` é exclusivamente para o texto do utilizador e nunca deve ser usado para exibir resultados de OCR.

## Fluxo de Sincronização e OCR

1.  **Envio pelo Cliente**: O frontend envia as páginas modificadas, incluindo os `stroke_data`, para o endpoint de sincronização.
    
    `POST /api/sync/pages/push`
    
    ```json
    {
      "pages": [
        {
          "notebook_id": 1,
          "page_number": 1,
          "stroke_data": [ /* ... traços ... */ ],
          "text_data": [ /* ... texto ... */ ]
        }
      ]
    }
    ```

2.  **Processamento no Backend**:
    - O backend salva a página.
    - Se a página contém novos `stroke_data` e ainda não tem um OCR recente, um job assíncrono (`ProcessPageOcr`) é agendado.
    - O backend responde imediatamente com a confirmação da sincronização, **sem esperar pelo resultado do OCR**.
    
    ```json
    {
      "message": "Páginas sincronizadas com JSON.",
      "synced_pages": [
        { "client_id": "some-uuid", "server_id": 101, "page_number": 1 }
      ]
    }
    ```

3.  **Resultado Assíncrono do OCR**: O job de OCR executa em segundo plano. Quando concluído, o backend atualiza os campos `extracted_text` e `ocr_data` da página no banco de dados. Este resultado **não é enviado ativamente** para o cliente.

## Como o Frontend Obtém o Resultado do OCR?

Como o processo é assíncrono e o backend não envia uma notificação ativa, o frontend precisa buscar o resultado.

### Estratégia Recomendada: Polling Curto

1.  Após uma sincronização bem-sucedida com `POST /api/sync/pages/push`, o frontend pode iniciar um *polling* (verificações periódicas) para a página atualizada.
2.  Faça um `GET /api/notebooks/{notebook_id}/pages?page={page_number}` para buscar a versão mais recente da página.
3.  Verifique se o campo `extracted_text` não está mais vazio.
4.  Para evitar sobrecarga, limite o número de tentativas e use um intervalo razoável (ex: a cada 3-5 segundos, por no máximo 1 minuto).

### Exemplo de Resposta com OCR

Quando o OCR estiver pronto, a resposta do `GET` para a página incluirá os dados:

```json
{
  "id": 101,
  "notebook_id": 1,
  "page_number": 1,
  "extracted_text": "Este é o texto reconhecido.",
  "ocr_data": [
    {
      "id": "uuid-ocr-1",
      "type": "ocr",
      "text": "Este é o texto reconhecido.",
      "engine": "my-engine",
      "language": "pt",
      "created_at": "...",
      /* ... outros metadados ... */
    }
  ],
  /* ... outros campos da página ... */
}
```

### Futuro: WebSockets (Próximos Passos)

Uma melhoria futura no backend poderá incluir o envio de um evento via WebSocket (ex: no canal `notebook.{notebook_id}`) para notificar o cliente quando o OCR estiver concluído, eliminando a necessidade de polling.

## Endpoints Relevantes

- **Sincronização de Páginas (aciona o OCR)**:
  - `POST /api/sync/pages/push`
- **Busca de Páginas (para obter o resultado do OCR)**:
  - `GET /api/notebooks/{notebook_id}/pages`
- **Reconhecimento Direto (para diagnóstico)**:
  - `POST /api/handwriting/recognize` (pode ser usado para testes, mas não faz parte do fluxo normal de sincronização).

## Resumo para o Frontend

- **Envie** os traços via `POST /api/sync/pages/push`.
- **Aguarde** a confirmação da sincronização.
- **Busque** a página atualizada periodicamente via `GET /api/notebooks/{notebook_id}/pages` para obter o resultado do OCR no campo `extracted_text`.
- **Exiba** o `extracted_text` na UI quando disponível.
- **Nunca** misture a lógica de `text_data` (do utilizador) com `extracted_text` (do OCR).
