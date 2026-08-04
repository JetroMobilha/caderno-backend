# Guia da API do Marketplace de Cadernos para Frontend (Flutter)

Este documento detalha os endpoints da API e os procedimentos para interagir com a loja de cadernos do Caderno-Backend.

## Autenticação

Todas as rotas descritas abaixo são protegidas e requerem que o token de autenticação do utilizador (obtido no login) seja enviado no cabeçalho `Authorization` como um `Bearer Token`.

```
Authorization: Bearer <SEU_TOKEN_DE_SESSÃO>
```

---

## 1. Publicar um Caderno na Loja

Para publicar um caderno, não existe um endpoint de "criação" na loja. Em vez disso, **atualiza-se um caderno existente** que pertence ao utilizador, definindo-o como público.

**Endpoint:** `PUT /api/notebooks/{notebook_id}`

**Descrição:** Modifica um caderno existente. Para publicá-lo, devem ser enviados os campos `is_published`, `description` e `price`.

**Corpo da Requisição (JSON):**

Para publicar um caderno **gratuito**:

```json
{
    "is_published": true,
    "description": "Um excelente resumo de Álgebra Linear, com exemplos práticos e exercícios resolvidos.",
    "price": 0.00,
    "author_name": "António da Silva" // Opcional, mas recomendado
}
```

Para publicar um caderno **pago**:

```json
{
    "is_published": true,
    "description": "O guia completo de Marketing Digital para 2026, com estratégias comprovadas.",
    "price": 1500.00, // Preço em Kwanzas (ou outra moeda base)
    "author_name": "Maria dos Santos"
}
```

**Para retirar um caderno da loja**, basta enviar o mesmo pedido com `is_published: false`.

---

## 2. Listar e Pesquisar Cadernos na Loja

Para exibir os cadernos disponíveis na loja para os utilizadores.

**Endpoint:** `GET /api/marketplace/notebooks`

**Descrição:** Retorna uma lista paginada de todos os cadernos publicados.

**Parâmetros de Query:**

*   `page` (opcional): O número da página que deseja obter. A API retorna 10 itens por página. Ex: `?page=2`.
*   `q` (opcional): Um termo de pesquisa para filtrar cadernos por título, descrição ou nome do autor. Ex: `?q=Matemática`.

**Exemplo de Chamada:**

```
GET /api/marketplace/notebooks?page=1&q=resumo
```

**Exemplo de Resposta (JSON):**

```json
{
    "data": [
        {
            "id": 15,
            "title": "Resumo de Biologia Celular",
            "description": "Apontamentos completos da matéria, ideais para frequência.",
            "author_name": "Joana Pinto",
            "price": "500.00",
            "cover_type": "color",
            "color": "#2a9d8f",
            // ...outros campos do caderno
        }
        // ...outros 9 cadernos
    ],
    "current_page": 1,
    "last_page": 5,
    "total": 50
}
```

---

## 3. Adquirir um Caderno da Loja

Quando um utilizador decide obter um caderno da loja (seja gratuito ou após um pagamento validado no frontend).

**Endpoint:** `POST /api/marketplace/notebooks/{id}/acquire`

**Descrição:** Clona o caderno da loja para a conta do utilizador autenticado. O backend trata de copiar o caderno e todas as suas páginas, associando-o ao novo dono.

**Parâmetros:**

*   `{id}`: O `id` do caderno a ser adquirido, obtido na listagem do marketplace.

**Corpo da Requisição:** Vazio.

**Exemplo de Chamada:**

```
POST /api/marketplace/notebooks/15/acquire
```

**Exemplo de Resposta de Sucesso (201 Created):**

```json
{
    "message": "Caderno transferido com sucesso!",
    "notebook": {
        "id": 102, // ID do NOVO caderno, na conta do utilizador atual
        "title": "Resumo de Biologia Celular",
        "subject_id": 25, // ID da matéria "Matérias Adquiridas 🛒"
        "original_notebook_id": 15, // Rastreia o caderno original
        // ...outros campos do caderno clonado
    }
}
```

**Exemplo de Resposta de Erro (400 Bad Request):**

Se o utilizador tentar adquirir um caderno que ele mesmo publicou.

```json
{
    "message": "Já és o proprietário original deste caderno."
}
```
