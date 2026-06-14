# 📓 Caderno Digital - Backend (API)

# 📓 Caderno Digital - Backend (API)

[![Testes Automatizados](https://github.com/JetroMobilha/caderno-backend/actions/workflows/tests.yml/badge.svg)](https://github.com/JetroMobilha/caderno-backend/actions/workflows/tests.yml)

Bem-vindo ao repositório backend do projeto **Caderno Digital**, uma plataforma educacional pensada e otimizada para os estudantes universitários em Angola. 🇦🇴

Este backend foi desenvolvido em **Laravel 10** e fornece uma API RESTful rápida e leve para ser consumida pela aplicação móvel feita em **Flutter**.

## ✨ Principais Funcionalidades

* 🔐 **Autenticação Segura:** Registo, Login e gestão de sessões via Laravel Sanctum.
* 📂 **Organização Hierárquica:** Gestão de Disciplinas (Subjects) e Cadernos (Notebooks).
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

## 📚 Documentação Completa

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