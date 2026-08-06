# 📓 Caderno Digital - Backend (API)

[![Testes Automatizados](https://github.com/JetroMobilha/caderno-backend/actions/workflows/tests.yml/badge.svg)](https://github.com/JetroMobilha/caderno-backend/actions/workflows/tests.yml)

Bem-vindo ao repositório backend do projeto **Caderno Digital**, uma plataforma educacional pensada e otimizada para os estudantes universitários em Angola. 🇦🇴

Este backend foi desenvolvido em **Laravel 10** e fornece uma API RESTful rápida e leve para ser consumida pela aplicação móvel feita em **Flutter**.

## ✨ Principais Funcionalidades

* 🔐 **Autenticação Segura:** Registo, Login e gestão de sessões via Laravel Sanctum.
* 📂 **Organização Hierárquica:** Gestão de Disciplinas (Subjects) e Cadernos (Notebooks).
* 🖨️ **Importação de PDF para Caderno:** Converte arquivos PDF em novos cadernos, com cada página do PDF se tornando uma página do caderno. (Ver [docs/flutter_pdf_import_guide.md](docs/flutter_pdf_import_guide.md) para detalhes de integração.)
* ✍️ **Sincronização Ultrarrápida:** Motor de desenho que guarda traços (strokes) em formato JSON, concebido para gastar o mínimo de dados móveis possível.
* 🤝 **Colaboração em Tempo Real:** Sistema de partilha de cadernos com permissões avançadas (Viewer / Editor).
* 💰 **Integração com Multicaixa:** Preparado para pagamentos locais em Kwanzas via referência Multicaixa para subscrições do Plano Pro.

## 🚀 Como testar o projeto localmente

### Pré-requisitos
* PHP 8.1 ou superior
* Composer
* MySQL (XAMPP/MAMP/Herd)

### Passo a Passo

1. **Clonar o repositório:**
```bash
git clone [https://github.com/JetroMobilha/caderno-backend.git](https://github.com/JetroMobilha/caderno-backend.git)
cd caderno-backend
```

2. **Instalar dependências:**
```bash
composer install
```

3. **Configurar as Variáveis de Ambiente:**
Copie o ficheiro de exemplo e configure a sua base de dados no ficheiro `.env`.
```bash
cp .env.example .env
php artisan key:generate
```

4. **Preparar a Base de Dados:**
```bash
php artisan migrate
```

5. **Ligar o Servidor:**
```bash
php artisan serve
```

A API estará disponível em `http://127.0.0.1:8000/api`.

## ✍️ Reconhecimento de escrita manual (gratuito)

O backend já inclui um fluxo para reconhecimento de texto a partir dos traços manuscritos que chegam do cliente. Em vez de depender de uma imagem enviada separadamente, o servidor transforma os dados de desenho (`stroke_data`) num ficheiro temporário e usa o motor open-source Tesseract, que é gratuito e pode correr localmente. Isto permite que, à medida que os dados do utilizador chegam ao servidor, o texto manuscrito seja convertido para texto digital e guardado para futuras pesquisas no caderno.

### Como usar

1. Instale as dependências do servidor:
   O Tesseract é o motor de OCR, e o Node.js é usado por um script auxiliar para processar os dados da escrita.

   <details>
   <summary>Instruções para Debian / Ubuntu (Recomendado para produção)</summary>

   Execute os seguintes comandos no terminal do seu servidor:

   ```bash
   # 1. Atualize a lista de pacotes
   sudo apt update

   # 2. Instale o Tesseract, o pacote de língua portuguesa, Node.js e NPM
   sudo apt install -y tesseract-ocr tesseract-ocr-por nodejs npm

   # 3. (Opcional) Verifique a instalação
   tesseract --version
   ```
   </details>

   <details>
   <summary>Instruções para Windows (Ambiente de desenvolvimento)</summary>

   - **Tesseract:** Faça o download do instalador oficial a partir do [repositório da UB Mannheim](https://github.com/UB-Mannheim/tesseract/wiki). Durante a instalação, **certifique-se de marcar o pacote de língua portuguesa** e adicione o diretório de instalação à variável de ambiente `PATH`.
   - **Node.js:** Descarregue e instale a partir do [site oficial do Node.js](https://nodejs.org/).

   </details>

2. Instale as dependências do helper Node no servidor do projeto:
```bash
cd /caminho/do/projeto
npm install
```

3. Defina o binário e a fila no `.env` do servidor:
```env
OCR_TESSERACT_PATH=/usr/bin/tesseract
OCR_NODE_PATH=/usr/bin/node
OCR_TESSDATA_DIR=/usr/share/tesseract-ocr/5/tessdata
OCR_QUEUE_NAME=ocr
OCR_QUEUE_TRIES=3
OCR_QUEUE_TIMEOUT=600
OCR_QUEUE_WORKERS=2
QUEUE_CONNECTION=database
```

4. Rode as migrações para criar a tabela de filas:
```bash
php artisan migrate
```

5. Inicie os workers de OCR em segundo plano. Para um servidor com 4GB de RAM, recomendamos começar com 2 workers e memória limitada por processo:
```bash
php artisan queue:work database --queue=ocr --tries=3 --timeout=600 --memory=1024 --sleep=5 --stop-when-empty
```

Se quiser gerir vários workers de forma mais estável, use Supervisor e defina `numprocs=2` para limitar a carga. Exemplo de configuração:
```ini
[program:caderno-ocr]
command=/usr/bin/php /var/www/caderno-backend/artisan queue:work database --queue=ocr --tries=3 --timeout=600 --memory=1024 --sleep=5 --stop-when-empty
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/caderno-ocr.log
```

6. Envie uma imagem para o endpoint protegido. Se quiser que o resultado seja guardado automaticamente na base de dados do caderno, envie também `notebook_id` e `page_number`:
```bash
curl -X POST http://127.0.0.1:8000/api/handwriting/recognize \
  -H "Authorization: Bearer SEU_TOKEN" \
  -F "image=@caminho/para/imagem.png" \
  -F "language=por" \
  -F "notebook_id=12" \
  -F "page_number=3"
```

Resposta esperada:
```json
{
  "success": true,
  "text": "Texto reconhecido",
  "engine": "tesseract",
  "language": "por",
  "saved_to_database": true,
  "page_id": 45,
  "notebook_id": 12,
  "context": {
    "subject": {"id": 1, "name": "Matemática"},
    "notebook": {"id": 12, "title": "Caderno A"},
    "page": {"id": 45, "number": 3}
  }
}
```

> O texto reconhecido é guardado automaticamente na coluna `extracted_text` da página correspondente e também registado numa coluna dedicada `ocr_data` com contexto estruturado (`subject`, `notebook`, `page`) para facilitar futuras pesquisas, filtros e automações no fluxo do caderno sem misturar esse conteúdo com o texto introduzido pelo utilizador.

### Arquitetura recomendada para produção

Esta solução usa filas de processamento para que o pedido HTTP não fique preso ao OCR. Em produção, o fluxo recomendado é:

- o cliente envia os traços para o backend;
- o backend guarda os dados da página rapidamente;
- um job em fila processa o OCR em segundo plano;
- quando o texto estiver pronto, ele é guardado automaticamente na base de dados.

Para manter o servidor estável com 4GB de RAM:
- use 2 workers de OCR no máximo no início;
- limite cada worker a 1GB de memória via `--memory=1024`;
- mantenha `OCR_QUEUE_TRIES=3` e `OCR_QUEUE_TIMEOUT=600`;
- se o volume crescer, aumente gradualmente para 3 ou 4 workers, mas só se houver CPU e RAM disponíveis.

> Esta implementação é uma base simples e gratuita para começar; pode ser evoluída para pré-processamento de imagem, modelos mais precisos e melhoria de qualidade para escrita manual.

## 📚 Documentação Completa

Para a integração do frontend com o fluxo de OCR, consulte a documentação específica em [docs/frontend-ocr-integration.md](docs/frontend-ocr-integration.md).

A documentação detalhada de todas as rotas da API e a estrutura da Base de Dados pode ser encontrada na nossa Wiki:
* [Documentação da API (Endpoints e JSONs)](https://github.com/JetroMobilha/caderno-backend/wiki/Documenta%C3%A7%C3%A3o-da-API)
* [Dicionário de Dados (Tabelas e Relações)](https://github.com/JetroMobilha/caderno-backend/wiki/Base-de-Dados)

## 🧪 Testes Automatizados

O nosso backend adota a filosofia TDD (Test-Driven Development). Para correr a bateria de testes e garantir a estabilidade do sistema, execute:
```bash
php artisan test
```

---
*Desenvolvido com dedicação por Jetro Mobilha.*