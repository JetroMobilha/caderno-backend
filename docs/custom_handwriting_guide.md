# Guia da API de Caligrafia Personalizada para Frontend (Flutter)

Este documento descreve como o frontend deve interagir com a API para permitir que um utilizador guarde a sua própria caligrafia, caractere por caractere.

## Autenticação

Tal como as outras rotas protegidas, este endpoint requer que o token de autenticação do utilizador seja enviado no cabeçalho `Authorization` como um `Bearer Token`.

```
Authorization: Bearer <SEU_TOKEN_DE_SESSÃO>
```

---

## 1. Guardar um Caractere da Caligrafia

Sempre que o utilizador desenha um caractere (seja 'a', 'B', '?', '5', etc.) e o aprova, o frontend deve enviar os dados desse desenho para este endpoint.

**Endpoint:** `POST /api/handwriting/alphabet`

**Descrição:** Guarda ou atualiza a definição de um único caractere para o utilizador autenticado. Se o utilizador desenhar a letra 'A' novamente, este endpoint irá substituir a versão anterior, permitindo a correção e melhoria contínua.

**Corpo da Requisição (JSON):**

O corpo deve conter o caractere em si e os dados dos traços (`stroke_data`). Os `stroke_data` devem ser um array de traços, onde cada traço é um array de pontos (x, y).

```json
{
    "character": "A",
    "stroke_data": [
        [ 
            { "x": 10, "y": 80 },
            { "x": 50, "y": 10 },
            { "x": 90, "y": 80 }
        ],
        [
            { "x": 30, "y": 50 },
            { "x": 70, "y": 50 }
        ]
    ]
}
```

**Explicação do `stroke_data`:**
*   O array principal contém todos os traços necessários para desenhar o caractere. No exemplo acima, a letra 'A' foi desenhada com 2 traços.
*   Cada traço é um array de objetos.
*   Cada objeto representa um ponto no espaço, com coordenadas `x` e `y`, na ordem em que foram desenhados.

**Exemplo de Resposta de Sucesso (200 OK):**

O servidor responde com uma mensagem de sucesso e os dados que foram guardados.

```json
{
    "message": "Caractere guardado com sucesso!",
    "data": {
        "user_id": 12,
        "character": "A",
        "stroke_data": "[ [ { "x": 10, "y": 80 }, ... ] ]", // Os dados como foram guardados
        "updated_at": "2026-08-04T12:30:00.000000Z",
        "created_at": "2026-08-04T12:30:00.000000Z",
        "id": 1
    }
}
```

## Próximos Passos (Backend)

Com estes dados guardados, o próximo passo no desenvolvimento do backend será utilizar o endpoint de síntese de escrita (`/api/handwriting/synthesize`) para que ele, em vez de usar uma fonte padrão, utilize a caligrafia personalizada do utilizador para "desenhar" o texto.
