# /refactor-screen

Refatora uma tela do painel admin: Blade + Livewire component.

Argumento opcional: `$ARGUMENTS` — caminho do arquivo Blade alvo (ex: `resources/views/livewire/admin/products/form.blade.php`). Se não informado, usa o arquivo aberto no editor.

---

## Processo obrigatório

### 1. Leitura e mapeamento

Leia TODOS estes arquivos antes de tocar em qualquer código:

- O Blade view alvo
- O Livewire component PHP correspondente (em `app/Livewire/`)
- Os componentes Blade já existentes em `resources/views/components/admin/`

Identifique:
- Padrões repetidos no Blade (cards, headers, botões, linhas de formulário)
- Métodos grandes no PHP (`mount`, `save`, `render`) que misturam responsabilidades
- Consultas no `render()` que poderiam ser extraídas

### 2. Componentes Blade reutilizáveis

Componentes disponíveis em `resources/views/components/admin/`:

| Componente | Props | Quando usar |
|---|---|---|
| `x-admin.form-card` | `title?` | Qualquer seção com card branco (`bg-white border rounded-xl`) |
| `x-admin.page-header` | `back-route`, `title` | Header com seta voltar + título da página |
| `x-admin.form-actions` | `save-label`, `saving-label?`, `cancel-route`, `save-action?` | Botões salvar/cancelar no fim do form |
| `x-admin.business-hours-row` | `day-index`, `day-label`, `is-active` | Linha de dia/horário no formulário de filial |

**Antes de criar novo componente:** verifique se o padrão já existe nos componentes acima.

**Criar novo componente quando:**
- O padrão se repete 2+ vezes no mesmo arquivo OU existe em outros arquivos admin
- A seção tem mais de ~15 linhas de markup puro sem lógica dinâmica complexa
- É estruturalmente idêntico entre telas (card, header, row, input group)

Novos componentes vão em `resources/views/components/admin/<nome>.blade.php` com `@props` declarados.

### 3. Refatoração do Blade

Regras:

- Zero lógica de negócio no Blade — só renderização e condicionais simples
- Substituir toda ocorrência de `bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700` por `<x-admin.form-card>`
- Substituir o padrão de header com seta voltar por `<x-admin.page-header>`
- Substituir o padrão de botões salvar/cancelar por `<x-admin.form-actions>`
- Se houver bloco Alpine.js `x-data` grande (>20 linhas): mantém inline por ora — é lógica de UI local, não extrai
- Preservar todos os `wire:model`, `wire:click`, `@error`, `x-show`, `x-cloak` exatos

### 4. Separação de JS e CSS

**JavaScript (Alpine.js e scripts inline)**

- Bloco `x-data` com mais de 20 linhas → extrair para `resources/js/admin/<nome>.js` usando `Alpine.data('nomeDoComponente', () => ({ ... }))`
- No Blade, substituir objeto inline por `x-data="nomeDoComponente"`
- Registrar o componente Alpine no entry point correto (ex: `resources/js/admin.js`)
- `<script>` blocks independentes do Alpine → mover para `resources/js/admin/<nome>.js` e importar no bundle

**CSS (estilos e blocos `<style>`)**

- `<style>` blocks dentro do Blade → mover para `resources/css/admin/<nome>.css` e importar no entry point correto
- Conjunto de classes Tailwind repetido 3+ vezes (idêntico) → extrair como `@apply` em arquivo CSS dedicado
- Estilos inline (`style=""`) → converter para classes Tailwind quando possível; se não, manter como está

Regras:
- Nunca criar arquivo JS/CSS sem importar no bundle — Vite precisa conhecer o arquivo
- `@push('scripts')` / `@push('styles')` apenas quando o script/style é exclusivo de uma única view
- Preferir `Alpine.data()` global quando o componente pode ser reutilizado em outras telas

### 5. Refatoração do Livewire Component PHP

Extrair métodos privados quando `mount()` ou `save()` tiverem mais de ~20 linhas:

**`mount()`** → separar em:
- `resolvePermissions(?Model $model)` — lógica de canSave, needsCompanySelect, permissões
- `fillFromModel(Model $model)` — preenchimento de propriedades na edição

**`save()`** → separar em:
- `buildPayload(array $validated)` ou método equivalente para construção do dado final
- `persistModel(array $data)` → `updateModel()` + `createModel()` quando há lógica diferente entre criar/editar

**`render()`** → se tiver query: verificar se pode ser computed property ou mover para método separado

Regras PHP:
- PSR-12 + preset laravel (Pint)
- Métodos privados no final da classe, após os públicos
- Early return em vez de else aninhado
- Nomes descritivos sem abreviação

### 6. Verificação final

Após todas as mudanças, execute nesta ordem:

```bash
composer lint
php artisan test --filter=Branch  # ou o módulo afetado
```

Se lint modificar arquivos, commit junto. Se testes quebrarem, corrigir antes de reportar.

---

## O que NÃO fazer

- Não extrair lógica de negócio para o Blade
- Não criar componente Livewire filho sem necessidade clara (evitar complexidade desnecessária)
- Não remover `wire:loading`, `wire:target` dos botões — são necessários para UX
- Não inventar componentes para uso único — DRY só quando há reutilização real
- Não mover queries do `render()` para `mount()` — o `render()` é chamado em cada update, `mount()` não
- Não alterar lógica de negócio durante refatoração — só estrutura

---

## Output esperado

Liste ao final:
1. Arquivos modificados e o que mudou em cada
2. Componentes criados (se algum)
3. Métodos extraídos no PHP (se algum)
4. Resultado do lint + testes
