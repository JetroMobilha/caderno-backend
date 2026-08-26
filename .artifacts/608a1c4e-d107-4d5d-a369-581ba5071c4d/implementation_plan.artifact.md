# Plano de Implementação: Correção da Duplicação de Cadernos e Conflitos de Sync

Este plano visa corrigir o bug onde o caderno original perde dados após uma duplicação ou aquisição no marketplace, causado por colisões de `client_id` e falta de escopo nas consultas de sincronização.

## Problemas Identificados

1.  **Colisão de `client_id` na Duplicação (Backend):** O método `replicate()` do Laravel copia todos os atributos, incluindo o `client_id` (UUID gerado pelo Flutter). Isso faz com que o servidor tenha dois registos diferentes com o mesmo identificador de cliente, confundindo o motor de sincronização.
2.  **Consultas de Sync Sem Escopo (Backend):** O `SyncController` e `SyncService` procuram itens pelo `client_id` em toda a base de dados, sem filtrar pelos cadernos aos quais o utilizador autenticado tem acesso. Isso pode fazer com que um utilizador "puxe" ou "atualize" dados de outro utilizador se houver colisão de IDs.
3.  **Perda de Dados no Flutter (Frontend):** Quando o cliente deteta um item com o mesmo `client_id` vindo do servidor (mas com IDs de servidor diferentes), a lógica de resolução de conflitos pode acabar por apagar ou sobrescrever os dados locais do caderno original com a versão (por vezes incompleta) do novo caderno.

## Propostas de Alteração

### Backend (Laravel)

#### [MODIFY] [MarketplaceController.php](file:///C:/xampp/htdocs/caderno-backend/app/Http/Controllers/Api/MarketplaceController.php)
- No método `acquire`, garantir que o novo caderno e as suas novas páginas recebam um `client_id` único (novo UUID).
- Limpar timestamps de sincronização para forçar o cliente a baixar tudo do novo caderno.

#### [MODIFY] [SyncController.php](file:///C:/xampp/htdocs/caderno-backend/app/Http/Controllers/Api/SyncController.php)
- No método `pushNotebooks`, filtrar a busca do `Notebook` por `client_id` garantindo que pertence ao utilizador ou é partilhado com ele.

#### [MODIFY] [SyncService.php](file:///C:/xampp/htdocs/caderno-backend/app/Services/SyncService.php)
- No método `processPageData`, filtrar a busca da `Page` por `client_id` garantindo que pertence a um caderno acessível pelo utilizador.

---

### Frontend (Flutter)

#### [MODIFY] [notebooks_controller.dart](file:///C:/Users/HP/StudioProjects/caderno-app/lib/features/notebooks/controllers/notebooks_controller.dart)
- No método `duplicateNotebook`, garantir que todos os metadados de sincronização (`serverId`, `syncedWithCloud`) são resetados para o novo caderno e páginas antes de salvar. (Já parece estar a ser feito, mas vou reforçar a limpeza).

## Plano de Verificação

### Testes Manuais
1.  **Duplicação Local:** Duplicar um caderno na app Flutter e verificar se o original mantém todos os traços e o novo também os tem.
2.  **Aquisição Marketplace:** Adquirir um caderno na loja e verificar se não interfere com nenhum caderno local existente.
3.  **Sincronização:** Após duplicar/adquirir, forçar uma sincronização e verificar na base de dados (SQLite e MySQL) se os `client_id` são distintos.
