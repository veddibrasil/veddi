<?php

namespace App\Services\Fiscal;

use App\Models\Branch;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalTemplate;
use App\Models\FiscalTemplateApplication;
use App\Models\Product;
use App\Models\User;
use App\Support\Fiscal\ProductFiscalProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tributação em lote: aplica um FiscalTemplate como cópia explícita dos valores aprovados no
 * cadastro geral (products.fiscal_data, vale para todas as filiais) ou na exceção de uma filial
 * (branch_product.fiscal_data, só o que muda nela; ver ProductFiscalProfile). Regras:
 * - prévia fiel ao que será gravado; a gravação confere que nada mudou desde a prévia;
 * - "preencher vazios" é o padrão; substituir valores existentes é escolha explícita;
 * - na filial, "vazio" e pendências olham o perfil efetivo (exceção + cadastro geral), valor
 *   igual ao do cadastro geral não vira exceção, e só produtos que já têm exceção nela são
 *   alterados (criar exceção continua sendo decisão no produto);
 * - no cadastro geral, a prévia avisa onde uma exceção de filial continua valendo por cima;
 * - cada aplicação guarda valores anteriores e novos, com usuário e data, para desfazer.
 */
class FiscalTemplateService
{
    public const MAX_PRODUCTS = 500;

    /** Campos exigidos pela NF-e (mesma lista da pré-checagem de emissão). */
    public const REQUIRED_FIELDS = [
        'ncm' => 'NCM',
        'cfop' => 'CFOP interno',
        'icms_situation' => 'CST / CSOSN ICMS',
        'origin' => 'Origem',
        'pis_situation' => 'CST PIS',
        'cofins_situation' => 'CST COFINS',
    ];

    /**
     * Regime e UF que um modelo novo já traz preenchidos: só quando todas as filiais concordam
     * (empresa com regimes ou UFs diferentes escolhe no formulário).
     *
     * @return array{crt: ?int, state: ?string}
     */
    public function companyDefaults(int $companyId): array
    {
        $crts = CompanyFiscalConfig::where('company_id', $companyId)->whereNotNull('branch_id')->whereNotNull('crt')->pluck('crt')->map(fn ($crt) => (int) $crt)->unique();
        // UF vem do endereço da filial (tabela addresses, via accessor).
        $states = Branch::withoutGlobalScopes()->where('company_id', $companyId)->with('addressRecord')->get()
            ->map(fn (Branch $branch) => mb_strtoupper(trim((string) $branch->state)))->filter()->unique();

        return [
            'crt' => $crts->count() === 1 ? $crts->first() : null,
            'state' => $states->count() === 1 ? $states->first() : null,
        ];
    }

    /**
     * Sugestões para começar: combinações fiscais já gravadas no cadastro geral dos produtos da
     * empresa e o padrão que a NFC-e ao consumidor usa hoje. Nada é salvo aqui: a tela abre o
     * formulário preenchido para conferir com a contabilidade. Some a sugestão que já virou modelo.
     *
     * @return array<int, array{key: string, name: string, crt: ?int, state: ?string, data: array<string, mixed>, products: array<int, string>, count: int, generic: bool, notes: string}>
     */
    public function suggestions(int $companyId, int $limit = 6): array
    {
        $defaults = $this->companyDefaults($companyId);
        $templates = FiscalTemplate::withoutGlobalScopes()->where('company_id', $companyId)->get();
        $taken = $templates->map(fn (FiscalTemplate $template) => sha1($this->canonical($template->filledValues())))->flip();
        $names = $templates->pluck('name')->map(fn ($name) => mb_strtolower($name))->flip()->all();

        $groups = [];
        Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $companyId)
            ->whereNotNull('fiscal_data')->with('category:id,name')->orderBy('name')
            ->get(['id', 'name', 'product_category_id', 'fiscal_data'])
            ->each(function (Product $product) use (&$groups) {
                $values = $this->suggestionValues($product->fiscal_data);
                if ($values === null) {
                    return;
                }
                $key = sha1($this->canonical($values));
                $groups[$key] ??= ['data' => $values, 'products' => [], 'categories' => []];
                $groups[$key]['products'][] = $product->name;
                $category = $product->category?->name ?? 'Produtos';
                $groups[$key]['categories'][$category] = ($groups[$key]['categories'][$category] ?? 0) + 1;
            });

        $suggestions = [];
        uasort($groups, fn ($a, $b) => count($b['products']) <=> count($a['products']));
        foreach ($groups as $key => $group) {
            if (isset($taken[$key])) {
                continue;
            }
            arsort($group['categories']);
            $count = count($group['products']);
            $suggestions[] = [
                'key' => $key,
                'label' => (string) array_key_first($group['categories']),
                'data' => $group['data'],
                'products' => array_slice($group['products'], 0, 3),
                'count' => $count,
                'generic' => false,
                'notes' => Str::limit("Sugestão a partir de {$count} produto(s) com estes dados no cadastro: ".implode(', ', array_slice($group['products'], 0, 5)).($count > 5 ? '…' : '').'. Confira com a contabilidade antes de aplicar em outros produtos.', 480),
            ];
        }
        $suggestions = array_slice($suggestions, 0, $limit - 1);

        // Padrão da NFC-e ao consumidor: CSOSN, logo só para Simples Nacional (ou regime não informado).
        $fallback = FiscalNoteService::NFCE_CONSUMER_FALLBACK;
        $fallbackKey = sha1($this->canonical($fallback));
        if (in_array($defaults['crt'], [null, 1], true) && ! isset($taken[$fallbackKey]) && ! isset($groups[$fallbackKey])) {
            $suggestions[] = [
                'key' => $fallbackKey,
                'label' => 'Padrão da NFC-e ao consumidor',
                'data' => $fallback,
                'products' => [],
                'count' => 0,
                'generic' => true,
                'notes' => 'Valores que a NFC-e ao consumidor (CPF ou sem documento) já usa hoje quando o produto não tem dados fiscais. São genéricos: confira NCM, CFOP e CSOSN com a contabilidade antes de usar em NF-e ou NFC-e com CNPJ.',
            ];
        }

        // Nome sugerido: rótulo + regime + UF, sem repetir nome de modelo existente nem entre sugestões.
        foreach ($suggestions as &$suggestion) {
            $crt = $this->suggestionCrt($suggestion['data'], $defaults['crt']);
            $base = Str::limit(implode(' – ', array_filter([
                $suggestion['label'],
                trim(($crt ? [1 => 'Simples', 2 => 'Simples sublimite', 3 => 'Regime normal'][$crt] : '').' '.($defaults['state'] ?? '')),
            ])), 74, '');
            $name = $base;
            for ($i = 2; isset($names[mb_strtolower($name)]); $i++) {
                $name = "{$base} {$i}";
            }
            $names[mb_strtolower($name)] = true;
            $suggestion['name'] = $name;
            $suggestion['crt'] = $crt;
            $suggestion['state'] = $defaults['state'];
            unset($suggestion['label']);
        }
        unset($suggestion);

        return $suggestions;
    }

    /** Campos de modelo de um cadastro de produto; null quando não há base para sugerir (sem NCM nem CST/CSOSN). */
    private function suggestionValues(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }
        $values = [];
        foreach (array_keys(FiscalTemplate::FIELDS) as $field) {
            $value = $data[$field] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $values[$field] = trim((string) $value);
            }
        }
        if (! isset($values['ncm']) && ! isset($values['icms_situation'])) {
            return null;
        }
        if (! empty($data['taxes']) && is_array($data['taxes']) && ! array_is_list($data['taxes'])) {
            $values['taxes'] = $data['taxes'];
        }

        return $values;
    }

    /** CSOSN (3 dígitos) é do Simples; CST (2 dígitos) usa o regime das filiais quando não for Simples. */
    private function suggestionCrt(array $values, ?int $companyCrt): ?int
    {
        $icms = (string) ($values['icms_situation'] ?? '');

        return match (strlen($icms)) {
            3 => in_array($companyCrt, [null, 1], true) ? 1 : null,
            2 => in_array($companyCrt, [2, 3], true) ? $companyCrt : null,
            default => $companyCrt,
        };
    }

    /** Valida os campos de um modelo; devolve só os preenchidos, normalizados. */
    public function validateTemplateData(array $fields, ?string $taxesJson, ?int $crt): array
    {
        $fields = array_map(fn ($value) => is_string($value) ? trim($value) : $value, array_intersect_key($fields, FiscalTemplate::FIELDS));
        $icmsRule = match ($crt) {
            1 => 'regex:/^[0-9]{3}$/',
            2, 3 => 'regex:/^[0-9]{2}$/',
            default => 'regex:/^[0-9]{2,3}$/',
        };
        $validator = Validator::make($fields, [
            'ncm' => ['nullable', 'regex:/^[0-9]{8}$/'],
            'cfop' => ['nullable', 'regex:/^5[0-9]{3}$/'],
            'interstate_cfop' => ['nullable', 'regex:/^6[0-9]{3}$/'],
            'icms_situation' => ['nullable', $icmsRule],
            'origin' => ['nullable', 'integer', 'between:0,8'],
            'unit' => ['nullable', 'string', 'max:6'],
            'cest' => ['nullable', 'regex:/^[0-9]{7}$/'],
            'pis_situation' => ['nullable', 'regex:/^[0-9]{2}$/'],
            'cofins_situation' => ['nullable', 'regex:/^[0-9]{2}$/'],
        ], [
            'icms_situation.regex' => $crt === 1 ? 'Simples Nacional (CRT 1) usa CSOSN de 3 dígitos.' : ($crt ? 'Regime normal usa CST de 2 dígitos.' : 'Informe CST (2 dígitos) ou CSOSN (3 dígitos).'),
        ], FiscalTemplate::FIELDS);
        $data = array_filter($validator->validate(), fn ($value) => $value !== null && $value !== '');
        if (isset($data['origin'])) {
            $data['origin'] = (string) $data['origin'];
        }

        $taxes = [];
        if (filled($taxesJson)) {
            $decoded = json_decode((string) $taxesJson, true);
            if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                throw ValidationException::withMessages(['taxes' => 'Campos adicionais devem ser um objeto JSON, por exemplo {"icms_aliquota": 18}.']);
            }
            foreach ($decoded as $key => $value) {
                if (! preg_match('/^(icms_|pis_|cofins_|ipi_|ibs_|cbs_|is_)/', (string) $key) || is_array($value) || is_object($value)
                    || in_array($key, ['icms_situacao_tributaria', 'icms_origem', 'pis_situacao_tributaria', 'cofins_situacao_tributaria'], true)) {
                    throw ValidationException::withMessages(['taxes' => "Campo adicional inválido: {$key}. Use os campos próprios para CST/CSOSN e origem."]);
                }
            }
            $taxes = $decoded;
        }
        if ($data === [] && $taxes === []) {
            throw ValidationException::withMessages(['fields' => 'Preencha ao menos um campo fiscal no modelo.']);
        }
        if ($taxes !== []) {
            $data['taxes'] = $taxes;
        }

        return $data;
    }

    /**
     * Prévia da aplicação, sem gravar nada.
     *
     * @param  array<int, int>  $productIds
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>, warnings: array<int, string>, blocked: ?string}
     */
    public function preview(FiscalTemplate $template, array $productIds, ?int $branchId, string $mode): array
    {
        return $this->compute($template, $productIds, $branchId, $mode, lock: false);
    }

    /**
     * Grava exatamente o que a prévia mostrou. $fingerprints vem da prévia: se o cadastro de
     * algum produto mudou desde então (outra pessoa editou), nada é gravado.
     *
     * @param  array<int|string, string>  $fingerprints  product_id => fingerprint
     */
    public function apply(FiscalTemplate $template, array $productIds, ?int $branchId, string $mode, array $fingerprints, User $user, ?string $templateFingerprint = null): FiscalTemplateApplication
    {
        return DB::transaction(function () use ($template, $productIds, $branchId, $mode, $fingerprints, $user, $templateFingerprint) {
            // O modelo também é conferido: editado depois da prévia, gravaria valores não mostrados.
            $template = FiscalTemplate::withoutGlobalScopes()->where('company_id', $template->company_id)->lockForUpdate()->findOrFail($template->id);
            if ($templateFingerprint !== null && $templateFingerprint !== $this->templateFingerprint($template)) {
                throw new \RuntimeException('O modelo foi alterado depois da prévia. Gere a prévia novamente.');
            }
            $result = $this->compute($template, $productIds, $branchId, $mode, lock: true);
            if ($result['blocked']) {
                throw new \RuntimeException($result['blocked']);
            }

            $changes = [];
            foreach ($result['rows'] as $row) {
                if ($row['skipped'] || $row['changed'] === []) {
                    continue;
                }
                if (($fingerprints[$row['product_id']] ?? null) !== $row['fingerprint']) {
                    throw new \RuntimeException('O cadastro fiscal de "'.$row['name'].'" mudou depois da prévia. Gere a prévia novamente.');
                }
                $this->write($template->company_id, (int) $row['product_id'], $branchId, $row['after']);
                $changes[] = ['product_id' => $row['product_id'], 'name' => $row['name'], 'before' => $row['before'], 'after' => $row['after']];
            }
            if ($changes === []) {
                throw new \RuntimeException('Nenhum produto teria alteração. Nada foi gravado.');
            }

            $application = FiscalTemplateApplication::create([
                'company_id' => $template->company_id,
                'fiscal_template_id' => $template->id,
                'template_name' => $template->name,
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'target' => $branchId ? 'branch' : 'general',
                'mode' => $mode,
                'products_count' => count($changes),
                'changes' => $changes,
            ]);

            Log::channel('fiscal')->info('Tributação em lote aplicada', [
                'company_id' => $template->company_id,
                'application_id' => $application->id,
                'template_id' => $template->id,
                'branch_id' => $branchId,
                'products' => count($changes),
            ]);

            return $application;
        });
    }

    /**
     * Desfaz uma aplicação: devolve os valores anteriores só onde o cadastro ainda está como a
     * aplicação deixou. Produto alterado depois disso fica como está (listado em skipped).
     *
     * @return array{restored: int, skipped: array<int, string>}
     */
    public function revert(FiscalTemplateApplication $application, User $user): array
    {
        return DB::transaction(function () use ($application, $user) {
            // Recarrega com trava pela empresa da própria aplicação (que já veio do escopo da tela).
            $application = FiscalTemplateApplication::withoutGlobalScopes()->where('company_id', $application->company_id)->lockForUpdate()->findOrFail($application->id);
            if ($application->reverted_at) {
                throw new \RuntimeException('Esta aplicação já foi desfeita.');
            }
            $restored = 0;
            $skipped = [];
            $ids = array_column($application->changes, 'product_id');
            $current = $this->currentProfiles($application->company_id, $ids, $application->branch_id, lock: true);
            foreach ($application->changes as $change) {
                $now = $current[$change['product_id']] ?? null;
                // Exceção que a aplicação esvaziou foi gravada como null (sem linha em $current).
                $unchanged = $now === null
                    ? $application->branch_id && $this->isEmptyProfile($change['after']) && $this->branchLinked($application->company_id, (int) $change['product_id'], $application->branch_id)
                    : $this->canonical($now['data']) === $this->canonical($change['after']);
                if (! $unchanged) {
                    $skipped[] = $change['name'];

                    continue;
                }
                $this->write($application->company_id, (int) $change['product_id'], $application->branch_id, $change['before']);
                $restored++;
            }
            $application->update(['reverted_at' => now(), 'reverted_by' => $user->id]);

            Log::channel('fiscal')->info('Tributação em lote desfeita', [
                'company_id' => $application->company_id,
                'application_id' => $application->id,
                'restored' => $restored,
                'skipped' => count($skipped),
            ]);

            return ['restored' => $restored, 'skipped' => $skipped];
        });
    }

    private function compute(FiscalTemplate $template, array $productIds, ?int $branchId, string $mode, bool $lock): array
    {
        $mode = $mode === 'replace' ? 'replace' : 'fill';
        $productIds = array_slice(array_values(array_unique(array_map('intval', $productIds))), 0, self::MAX_PRODUCTS);
        $values = $template->filledValues();
        $result = ['rows' => [], 'summary' => ['changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'resolved' => 0, 'remaining' => 0], 'warnings' => [], 'blocked' => null,
            'template_fingerprint' => $this->templateFingerprint($template)];

        // Filial conferida contra a empresa do modelo, não contra o tenant da sessão.
        $branch = $branchId ? Branch::withoutGlobalScopes()->where('company_id', $template->company_id)->find($branchId) : null;
        if ($branchId && ! $branch) {
            $result['blocked'] = 'Filial não encontrada nesta empresa.';

            return $result;
        }
        if (! $template->isValidOn(now())) {
            $result['blocked'] = 'Modelo fora da vigência. Revise as datas com a contabilidade antes de aplicar.';

            return $result;
        }
        $configs = CompanyFiscalConfig::where('company_id', $template->company_id)->whereNotNull('branch_id')->get(['branch_id', 'crt']);
        if ($branch) {
            $branchCrt = $configs->firstWhere('branch_id', $branch->id)?->crt;
            if ($template->crt && $branchCrt && (int) $branchCrt !== (int) $template->crt) {
                $result['blocked'] = "Modelo para CRT {$template->crt}, mas a filial {$branch->name} emite com CRT {$branchCrt}.";

                return $result;
            }
            if ($template->state && $branch->state && mb_strtoupper($branch->state) !== $template->state) {
                $result['blocked'] = "Modelo para a UF {$template->state}, mas a filial {$branch->name} fica em {$branch->state}.";

                return $result;
            }
        } elseif ($template->crt && $configs->contains(fn ($config) => $config->crt && (int) $config->crt !== (int) $template->crt)) {
            $result['warnings'][] = "Há filial com regime diferente do CRT {$template->crt} do modelo: nela, produtos sem exceção própria vão falhar na emissão. Para regimes diferentes, aplique o modelo do regime certo na exceção daquela filial.";
        }

        // Escopo explícito pela empresa do modelo (a tela, o lote e os testes podem rodar sem
        // tenant ligado); só o CompanyScope sai, produto excluído continua fora.
        $products = Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $template->company_id)->whereIn('id', $productIds)->orderBy('name')->get(['id', 'name', 'fiscal_data']);
        $current = $this->currentProfiles($template->company_id, $products->pluck('id')->all(), $branchId, $lock);

        // Destino cadastro geral: exceções das filiais que continuam valendo por cima dele.
        $exceptions = $branchId ? collect() : $this->branchExceptions($template->company_id, $products->pluck('id')->all());
        $touched = array_intersect(array_keys($values), array_keys(ProductFiscalProfile::FIELDS));

        foreach ($products as $product) {
            $profile = $current[$product->id] ?? null;
            $row = ['product_id' => $product->id, 'name' => $product->name, 'skipped' => null, 'changed' => [], 'before' => [], 'after' => [], 'fingerprint' => null, 'missing_before' => [], 'missing_after' => [], 'warnings' => []];
            if ($profile === null) {
                $row['skipped'] = $branchId ? 'Sem exceção nesta filial: usa o cadastro geral. Para mudar todas as filiais, escolha o cadastro geral; para criar uma exceção, abra o produto.' : 'Produto não encontrado.';
                $result['rows'][] = $row;
                $result['summary']['skipped']++;

                continue;
            }
            $general = is_array($product->fiscal_data) ? $product->fiscal_data : [];
            $before = is_array($profile['data']) ? $profile['data'] : [];
            // Pendência e "campo vazio" olham o que a nota usa: na filial, exceção + cadastro geral.
            $effective = fn (array $data) => $branchId ? ProductFiscalProfile::resolve($general, $data)['data'] : $data;
            $after = $this->merge($before, $values, $mode, $effective($before));
            if ($branchId && ! ProductFiscalProfile::isStandalone($general, $after)) {
                // Valor do modelo igual ao do cadastro geral não vira exceção: a filial segue o geral.
                foreach ($touched as $field) {
                    if (ProductFiscalProfile::same($after[$field] ?? null, $general[$field] ?? null)) {
                        unset($after[$field]);
                    }
                }
            }
            $row['before'] = $before;
            $row['after'] = $after;
            $row['fingerprint'] = sha1($this->canonical($before));
            $row['changed'] = array_values(array_filter(
                array_unique([...array_keys($before), ...array_keys($after)]),
                fn ($key) => $this->canonical($before[$key] ?? null) !== $this->canonical($after[$key] ?? null),
            ));
            $row['missing_before'] = $this->missing($effective($before));
            $row['missing_after'] = $this->missing($effective($after));
            $row['warnings'] = $this->conditionalWarnings($effective($after));
            foreach ($exceptions->get($product->id, []) as $exception) {
                if (ProductFiscalProfile::isStandalone($after, $exception['data'])) {
                    $row['warnings'][] = "A filial {$exception['branch']} tem perfil de outro regime para este produto: o cadastro geral não vale lá.";

                    continue;
                }
                $kept = array_intersect_key(ProductFiscalProfile::overrides($after, $exception['data']), array_flip($touched));
                if ($kept !== []) {
                    $row['warnings'][] = "Na filial {$exception['branch']} continua valendo a exceção própria: "
                        .collect($kept)->map(fn ($value, $field) => ProductFiscalProfile::FIELDS[$field].' '.$value)->implode(', ')
                        .'. Para usar o cadastro geral lá também, abra o produto e apague a exceção.';
                }
            }
            $result['summary'][$row['changed'] === [] ? 'unchanged' : 'changed']++;
            $result['summary']['resolved'] += count($row['missing_before']) - count(array_intersect($row['missing_before'], $row['missing_after']));
            $result['summary']['remaining'] += count($row['missing_after']);
            $result['rows'][] = $row;
        }

        $result['warnings'][] = 'Grupos IBS/CBS, benefícios e versões de tabela não são conferidos aqui: valide na homologação com a contabilidade.';

        return $result;
    }

    /** @return array<int, array{data: mixed}> perfil atual por produto (cadastro geral ou perfil da filial) */
    /**
     * Produtos cujo perfil no destino (cadastro geral ou perfil próprio da filial) ainda não tem
     * todos os campos exigidos pela NF-e — filtro "só pendentes" da tela. Na filial, só conta
     * quem já tem perfil próprio (os demais usam o cadastro geral, que é outro destino).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function incompleteProductIds(int $companyId, array $productIds, ?int $branchId): array
    {
        return collect($this->listStatus($companyId, $productIds, $branchId))
            ->filter(fn (array $status) => $status['editable'] && $status['missing'] !== [])
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Situação de cada produto na lista da tela, olhando o que a nota usa no destino escolhido.
     * Cadastro geral: campos vazios nele e filiais com exceção. Filial: campos vazios no perfil
     * efetivo (exceção + cadastro geral) e se há exceção para alterar.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{missing: array<int, string>, editable: bool, exceptions: array<int, string>}>
     */
    public function listStatus(int $companyId, array $productIds, ?int $branchId): array
    {
        if ($productIds === []) {
            return [];
        }
        $general = Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $companyId)->whereIn('id', $productIds)
            ->pluck('fiscal_data', 'id')->map(fn ($data) => is_array($data) ? $data : (json_decode((string) $data, true) ?: []));
        $exceptions = $this->branchExceptions($companyId, $general->keys()->all());

        return $general->mapWithKeys(function (array $data, int $id) use ($exceptions, $branchId) {
            $productExceptions = collect($exceptions->get($id, []));
            if (! $branchId) {
                return [$id => ['missing' => $this->missing($data), 'editable' => true, 'exceptions' => $productExceptions->pluck('branch')->all()]];
            }
            $own = $productExceptions->firstWhere('branch_id', $branchId);

            return [$id => [
                'missing' => $this->missing(ProductFiscalProfile::resolve($data, $own['data'] ?? null)['data']),
                'editable' => $own !== null,
                'exceptions' => $productExceptions->pluck('branch')->all(),
            ]];
        })->all();
    }

    /** @return \Illuminate\Support\Collection<int, array<int, array{branch_id: int, branch: string, data: array}>> exceções por produto */
    private function branchExceptions(int $companyId, array $productIds): \Illuminate\Support\Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return DB::table('branch_product')
            ->join('branches', 'branches.id', '=', 'branch_product.branch_id')
            ->where('branches.company_id', $companyId)
            ->whereIn('branch_product.product_id', $productIds)
            ->whereNotNull('branch_product.fiscal_data')
            ->orderBy('branches.name')
            ->get(['branch_product.product_id', 'branch_product.branch_id', 'branches.name', 'branch_product.fiscal_data'])
            ->map(fn ($row) => ['product_id' => (int) $row->product_id, 'branch_id' => (int) $row->branch_id, 'branch' => $row->name, 'data' => is_array($decoded = json_decode($row->fiscal_data, true)) ? $decoded : []])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->values()->all());
    }

    private function currentProfiles(int $companyId, array $productIds, ?int $branchId, bool $lock): array
    {
        if ($productIds === []) {
            return [];
        }
        if ($branchId) {
            return DB::table('branch_product')
                ->join('branches', 'branches.id', '=', 'branch_product.branch_id')
                ->where('branches.company_id', $companyId)
                ->where('branch_product.branch_id', $branchId)
                ->whereIn('branch_product.product_id', $productIds)
                ->whereNotNull('branch_product.fiscal_data')
                ->when($lock, fn ($q) => $q->lockForUpdate())
                ->get(['branch_product.product_id', 'branch_product.fiscal_data'])
                ->mapWithKeys(fn ($row) => [$row->product_id => ['data' => json_decode($row->fiscal_data, true)]])
                ->all();
        }

        return Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $companyId)->whereIn('id', $productIds)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['id', 'fiscal_data'])
            ->mapWithKeys(fn (Product $product) => [$product->id => ['data' => $product->fiscal_data ?? []]])
            ->all();
    }

    private function write(int $companyId, int $productId, ?int $branchId, array $data): void
    {
        if ($branchId) {
            // Exceção sem nenhum campo próprio deixa de existir: a filial volta a usar só o cadastro geral.
            DB::table('branch_product')->where('branch_id', $branchId)->where('product_id', $productId)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('branches')->whereColumn('branches.id', 'branch_product.branch_id')->where('branches.company_id', $companyId))
                ->update(['fiscal_data' => $this->isEmptyProfile($data) ? null : json_encode($data, JSON_THROW_ON_ERROR)]);

            return;
        }
        Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $companyId)->whereKey($productId)->update(['fiscal_data' => json_encode($data, JSON_THROW_ON_ERROR)]);
    }

    /** $basis: o que a nota usa hoje (na filial, exceção + cadastro geral); "vazio" é vazio nele. */
    private function isEmptyProfile(array $data): bool
    {
        return array_filter(array_intersect_key($data, ProductFiscalProfile::FIELDS), [ProductFiscalProfile::class, 'filled']) === []
            && empty($data['taxes']);
    }

    private function branchLinked(int $companyId, int $productId, int $branchId): bool
    {
        return DB::table('branch_product')->join('branches', 'branches.id', '=', 'branch_product.branch_id')
            ->where('branches.company_id', $companyId)->where('branch_product.branch_id', $branchId)
            ->where('branch_product.product_id', $productId)->whereNull('branch_product.fiscal_data')->exists();
    }

    private function merge(array $current, array $values, string $mode, ?array $basis = null): array
    {
        $basis ??= $current;
        $result = $current;
        foreach ($values as $field => $value) {
            if ($field === 'taxes') {
                $taxes = is_array($current['taxes'] ?? null) ? $current['taxes'] : [];
                $result['taxes'] = $mode === 'replace' ? array_replace($taxes, $value) : array_replace($value, $taxes);

                continue;
            }
            $empty = ! isset($basis[$field]) || $basis[$field] === '' || $basis[$field] === null;
            if ($mode === 'replace' || $empty) {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    private function missing(array $data): array
    {
        return array_values(array_map(
            fn ($field) => self::REQUIRED_FIELDS[$field],
            array_filter(array_keys(self::REQUIRED_FIELDS), fn ($field) => ! isset($data[$field]) || $data[$field] === ''),
        ));
    }

    /**
     * Requisitos condicionais conhecidos, como aviso (não bloqueiam): a classificação é decisão
     * da contabilidade e nenhum código é preenchido para "passar" na validação.
     */
    private function conditionalWarnings(array $data): array
    {
        $icms = (string) ($data['icms_situation'] ?? '');
        $taxes = is_array($data['taxes'] ?? null) ? $data['taxes'] : [];
        $warnings = [];
        if (in_array($icms, ['10', '30', '60', '70', '201', '202', '203', '500'], true) && blank($data['cest'] ?? null)) {
            $warnings[] = "CST/CSOSN {$icms} indica substituição tributária: confira o CEST.";
        }
        if (in_array($icms, ['60', '500'], true) && ($data['cfop'] ?? '') === '5102') {
            $warnings[] = "ICMS retido por ST ({$icms}) com CFOP 5102: confira se o CFOP correto é 5405.";
        }
        if (in_array($icms, ['00', '10', '20', '70', '90'], true) && ! isset($taxes['icms_aliquota'])) {
            $warnings[] = "CST {$icms} tributa ICMS na operação: informe a alíquota (icms_aliquota) nos campos adicionais.";
        }
        foreach (['pis' => 'PIS', 'cofins' => 'COFINS'] as $tax => $label) {
            if (in_array((string) ($data[$tax.'_situation'] ?? ''), ['01', '02'], true) && ! isset($taxes[$tax.'_aliquota_porcentual'])) {
                $warnings[] = "CST {$label} {$data[$tax.'_situation']} exige alíquota ({$tax}_aliquota_porcentual) nos campos adicionais.";
            }
        }

        return $warnings;
    }

    public function templateFingerprint(FiscalTemplate $template): string
    {
        return sha1($this->canonical([
            'values' => $template->filledValues(), 'crt' => $template->crt, 'state' => $template->state,
            'from' => $template->valid_from?->toDateString(), 'until' => $template->valid_until?->toDateString(),
        ]));
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            $value = array_map(fn ($item) => is_array($item) ? json_decode($this->canonical($item), true) : (is_int($item) || is_float($item) ? (string) $item : $item), $value);
        } elseif (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
