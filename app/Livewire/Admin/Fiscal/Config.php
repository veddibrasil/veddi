<?php

namespace App\Livewire\Admin\Fiscal;

use App\Exceptions\FocusNfeCompanyRegistrationException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Services\Fiscal\FocusNfeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class Config extends Component
{
    use WithFileUploads;

    public int $crt = 1;

    public bool $enabled = false;

    public string $inscricaoEstadual = '';

    public string $provider = 'focus_nfe';

    public string $providerToken = '';

    public bool $hasProviderToken = false;

    public string $environment = 'homologacao';

    public string $nfceSerie = '1';

    public $certificateFile = null;

    public bool $hasCertificate = false;

    public string $certificatePassword = '';

    public bool $canManage = false;

    public ?string $focusNfeCompanyId = null;

    // Campo que falta no cadastro (não existe tela de edição de CNPJ hoje) — só fica
    // editável aqui enquanto a empresa não tiver um CNPJ válido cadastrado.
    public string $ownerCpfCnpj = '';

    public bool $hasOwnerCpfCnpj = false;

    public string $inscricaoMunicipal = '';

    public string $cscNfceProducao = '';

    public bool $hasCscNfceProducao = false;

    public string $idTokenNfceProducao = '';

    // Dados já cadastrados em outras telas (empresa/filial) — exibidos aqui como
    // somente leitura para conferência, sem duplicar o ponto de edição.
    public string $companyName = '';

    public string $companyEmail = '';

    public string $branchAddress = '';

    public string $branchNumber = '';

    public string $branchComplement = '';

    public string $branchNeighborhood = '';

    public string $branchCity = '';

    public string $branchState = '';

    public string $branchCep = '';

    public string $branchPhone = '';

    public bool $hasBranch = false;

    // Empresa pode ter mais de uma filial — o registro na Focus NFe usa o endereço
    // de uma única filial, então o usuário escolhe qual quando houver mais de uma.
    public ?int $branchId = null;

    /** @var array<int, array{id: int, name: string}> */
    public array $branchOptions = [];

    public function mount(): void
    {
        $company = app('current.company');
        $user = auth()->user();

        $this->canManage = $user->hasPermission('fiscal.settings', $company);

        $this->companyName = $company->name;
        $this->companyEmail = $company->email ?? '';
        // Trava só depois que o CNPJ já foi registrado na Focus NFe (não apenas
        // preenchido) — antes disso, corrigir um CNPJ digitado errado não pode
        // exigir suporte, já que a Focus ainda nem conhece esse emissor.
        $this->hasOwnerCpfCnpj = $company->fiscalConfig?->isRegisteredWithFocus() ?? false;
        $this->ownerCpfCnpj = $company->owner_cpf_cnpj ?? '';

        $branches = Branch::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get();
        $this->hasBranch = $branches->isNotEmpty();
        $this->branchOptions = $branches->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->all();

        $config = $company->fiscalConfig;

        // Preserva a filial escolhida em um save anterior; sem escolha prévia, assume
        // a primeira só como ponto de partida — o usuário troca no select se precisar.
        $selectedBranch = $config?->branch_id ? $branches->firstWhere('id', $config->branch_id) : null;
        $branch = $selectedBranch ?? $branches->first();

        $this->branchId = $branch?->id;
        $this->fillBranchAddressFields($branch);

        if ($config) {
            $this->crt = $config->crt;
            $this->enabled = $config->enabled;
            $this->inscricaoEstadual = $config->inscricao_estadual ?? '';
            $this->inscricaoMunicipal = $config->inscricao_municipal ?? '';
            $this->provider = $config->provider;
            $this->environment = $config->environment;
            $this->nfceSerie = (string) $config->nfce_serie;
            $this->hasProviderToken = filled($config->provider_token);
            $this->hasCertificate = filled($config->certificate_path);
            $this->focusNfeCompanyId = $config->focus_nfe_company_id;
            $this->hasCscNfceProducao = filled($config->csc_nfce_producao);
            $this->idTokenNfceProducao = $config->id_token_nfce_producao ?? '';
        }
    }

    public function updatedBranchId(): void
    {
        $company = app('current.company');
        $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId);

        // Ignora seleção de filial que não pertence à empresa atual (branchId é
        // propriedade pública, então chega do cliente sem garantia de origem).
        if (! $branch) {
            $this->branchId = null;
        }

        $this->fillBranchAddressFields($branch);
    }

    private function fillBranchAddressFields(?Branch $branch): void
    {
        $this->branchAddress = $branch?->address ?? '';
        $this->branchNumber = $branch?->number ?? '';
        $this->branchComplement = $branch?->complement ?? '';
        $this->branchNeighborhood = $branch?->neighborhood ?? '';
        $this->branchCity = $branch?->city ?? '';
        $this->branchState = $branch?->state ?? '';
        $this->branchCep = $branch?->cep ?? '';
        $this->branchPhone = $branch?->phone ?? '';
    }

    public function save(): void
    {
        $company = app('current.company');
        abort_unless($this->canManage, 403);

        $this->validate([
            'crt' => ['required', 'integer', 'in:1,2,3'],
            'providerToken' => ['nullable', 'string', 'max:255'],
            'environment' => ['required', 'in:homologacao,producao'],
            'nfceSerie' => ['required', 'integer', 'min:1'],
            'inscricaoEstadual' => ['nullable', 'string', 'max:50'],
            'inscricaoMunicipal' => ['nullable', 'string', 'max:50'],
            'ownerCpfCnpj' => ['nullable', 'string'],
            'cscNfceProducao' => ['nullable', 'string', 'max:255'],
            'idTokenNfceProducao' => ['nullable', 'string', 'max:20'],
            'certificateFile' => ['nullable', 'file', 'mimes:pfx,p12', 'max:2048'],
            'certificatePassword' => ['nullable', 'string', 'max:255'],
        ]);

        // CNPJ não tem tela própria de edição — só é gravável aqui enquanto a empresa
        // ainda não foi registrada na Focus NFe; uma vez registrada, o campo fica
        // travado (disabled na view) para não divergir do que a Focus já reconhece
        // como emissor. Antes disso, digitar errado e corrigir não deve depender de suporte.
        if (! $this->hasOwnerCpfCnpj && $this->ownerCpfCnpj !== '') {
            $digits = preg_replace('/\D/', '', $this->ownerCpfCnpj);

            if (strlen($digits) !== 14) {
                $this->addError('ownerCpfCnpj', 'CNPJ deve ter 14 dígitos.');

                return;
            }

            $company->update(['owner_cpf_cnpj' => $digits]);
            $this->ownerCpfCnpj = $digits;
        }

        $config = $company->fiscalConfig ?? new CompanyFiscalConfig(['company_id' => $company->id]);

        // Reconfirma que a filial pertence à empresa atual antes de gravar — branchId
        // é propriedade pública do componente, não dado validado por relacionamento.
        $branch = $this->branchId
            ? Branch::withoutGlobalScopes()->where('company_id', $company->id)->find($this->branchId)
            : null;
        $config->branch_id = $branch?->id;

        $config->crt = $this->crt;
        $config->enabled = $this->enabled;
        $config->inscricao_estadual = $this->inscricaoEstadual ?: null;
        $config->inscricao_municipal = $this->inscricaoMunicipal ?: null;
        $config->provider = $this->provider;
        $config->environment = $this->environment;
        $config->nfce_serie = (int) $this->nfceSerie;
        $config->id_token_nfce_producao = $this->idTokenNfceProducao ?: null;

        // Token/senha/CSC só são sobrescritos quando o usuário digita um novo valor —
        // o campo fica em branco no mount() para não expor o segredo já salvo.
        if ($this->providerToken !== '') {
            $config->provider_token = $this->providerToken;
        }

        if ($this->certificatePassword !== '') {
            $config->certificate_password = $this->certificatePassword;
        }

        if ($this->cscNfceProducao !== '') {
            $config->csc_nfce_producao = $this->cscNfceProducao;
        }

        // Habilitar o módulo exige registrar (ou atualizar) o CNPJ emissor na Focus NFe
        // (POST/PUT /v2/empresas) antes de qualquer coisa ser persistida localmente —
        // do contrário a empresa fica "habilitada" sem a Focus reconhecer o emissor,
        // e a emissão de nota real falha silenciosamente.
        if ($this->enabled) {
            $cnpj = preg_replace('/\D/', '', $company->owner_cpf_cnpj ?? '');

            if (strlen($cnpj) !== 14) {
                $this->addError('enabled', 'Cadastre o CNPJ da empresa antes de habilitar a emissão fiscal.');

                return;
            }

            try {
                $this->registerWithFocusNfe($company, $config, $cnpj, $branch);
            } catch (FocusNfeCompanyRegistrationException $e) {
                $this->mapFocusErrorsToForm($company, $e);

                return;
            } catch (ConnectionException|\Throwable $e) {
                Log::channel('fiscal')->error('Focus NFe: falha ao registrar empresa', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);

                $this->addError('enabled', 'Não foi possível confirmar o cadastro com a Focus NFe agora. Tente novamente em instantes.');

                return;
            }
        }

        if ($this->certificateFile) {
            if ($config->certificate_path) {
                Storage::disk('local')->delete($config->certificate_path);
            }

            $config->certificate_path = $this->certificateFile->storeAs(
                'fiscal-certificates',
                "company-{$company->id}.".$this->certificateFile->getClientOriginalExtension(),
                'local',
            );
        }

        $config->save();

        $this->certificateFile = null;
        $this->certificatePassword = '';
        $this->providerToken = '';
        $this->cscNfceProducao = '';
        $this->hasProviderToken = filled($config->provider_token);
        $this->hasCertificate = filled($config->certificate_path);
        $this->hasCscNfceProducao = filled($config->csc_nfce_producao);
        $this->focusNfeCompanyId = $config->focus_nfe_company_id;
        $this->hasOwnerCpfCnpj = $config->isRegisteredWithFocus();

        session()->flash('status', 'Configurações fiscais salvas.');
    }

    private function registerWithFocusNfe(Company $company, CompanyFiscalConfig $config, string $cnpj, ?Branch $branch): void
    {
        // Filial criada no onboarding nasce sem endereço (preenchimento é feito depois
        // em /admin/filiais) — sem isso a Focus rejeita com "Município inválido:  -",
        // mensagem que não indica ao usuário o que corrigir. Valida antes de enviar.
        // Também cobre o caso de nenhuma filial selecionada (branchId inválido).
        if (! $branch?->address || ! $branch?->city || ! $branch?->state) {
            throw new FocusNfeCompanyRegistrationException(
                message: 'Cadastre o endereço completo da filial (endereço, cidade e UF) antes de habilitar a emissão fiscal.',
                statusCode: 422,
                errors: [],
                rawResponse: [],
            );
        }

        $payload = [
            'nome' => $company->name,
            'cnpj' => $cnpj,
            'inscricao_estadual' => $this->inscricaoEstadual !== '' ? $this->inscricaoEstadual : 'ISENTO',
            'inscricao_municipal' => $this->inscricaoMunicipal ?: null,
            'regime_tributario' => $this->crt,
            'logradouro' => $branch?->address,
            'numero' => $branch?->number,
            'complemento' => $branch?->complement,
            'bairro' => $branch?->neighborhood,
            'municipio' => $branch?->city,
            'uf' => $branch?->state,
            'cep' => preg_replace('/\D/', '', $branch?->cep ?? '') ?: null,
            'habilita_nfce' => true,
            'email' => $company->email,
            'telefone' => $branch?->phone,
        ];

        if ($this->certificateFile) {
            $certificateContent = file_get_contents($this->certificateFile->getRealPath());
            $password = $this->certificatePassword !== '' ? $this->certificatePassword : $config->certificate_password;

            // Valida arquivo+senha localmente (ext-openssl) antes de ir pra Focus —
            // sem isso o erro só aparece depois do round-trip com a API deles.
            if (! openssl_pkcs12_read($certificateContent, $parsedCertificate, (string) $password)) {
                throw new FocusNfeCompanyRegistrationException(
                    message: 'Não foi possível ler o certificado com a senha informada. Verifique o arquivo (.pfx/.p12) e a senha.',
                    statusCode: 422,
                    errors: [],
                    rawResponse: [],
                );
            }

            $payload['arquivo_certificado_base64'] = base64_encode($certificateContent);
            $payload['senha_certificado'] = $password;
        }

        $csc = $this->cscNfceProducao !== '' ? $this->cscNfceProducao : $config->csc_nfce_producao;

        if ($csc) {
            $payload['csc_nfce_producao'] = $csc;
        }

        if ($this->idTokenNfceProducao !== '') {
            $payload['id_token_nfce_producao'] = $this->idTokenNfceProducao;
        }

        // Registro é feito pela conta integradora da plataforma na Focus NFe
        // (o token por empresa só existe depois do registro, para emissão de nota).
        $service = new FocusNfeService(
            config('fiscal.focus_nfe.token'),
            $this->baseUrlForRegistration(),
        );

        $response = $config->focus_nfe_company_id
            ? $service->updateCompany($config->focus_nfe_company_id, $payload)
            : $service->createCompany($payload);

        $config->focus_nfe_company_id = $response['id'] ?? $config->focus_nfe_company_id;
        $config->token_producao = $response['token_producao'] ?? $config->token_producao;
        $config->token_homologacao = $response['token_homologacao'] ?? $config->token_homologacao;
        $config->focus_nfe_registered_at = now();

        $this->ensureWebhookRegistered($cnpj, $config, $service, $company);
    }

    /**
     * Registra o webhook de status assíncrono (POST /v2/hooks) pro CNPJ da empresa,
     * pra Focus NFe avisar autorização/rejeição de NFC-e no FiscalWebhookController
     * em vez de depender só de query() manual.
     *
     * Best-effort: não bloqueia o cadastro da empresa (já feito acima) se falhar —
     * sem webhook o sistema ainda funciona via consulta manual, só perde a
     * atualização automática de status.
     */
    private function ensureWebhookRegistered(string $cnpj, CompanyFiscalConfig $config, FocusNfeService $service, Company $company): void
    {
        if ($config->focus_nfe_webhook_id) {
            return;
        }

        // Focus não consegue chamar uma URL local — local/testing não tem APP_URL
        // pública, então o registro só ficaria tentando e falhando à toa.
        if (app()->environment(['local', 'testing'])) {
            Log::channel('fiscal')->debug('Focus NFe: registro de webhook pulado (ambiente local/testing)', [
                'company_id' => $company->id,
            ]);

            return;
        }

        try {
            // Focus NFe não tem evento "nfce" isolado — autorização de NFC-e (modelo 65)
            // e NF-e (modelo 55) compartilham o evento "nfe". "nfce_contingencia" é caso
            // à parte (emissão em contingência), não cobre autorização normal.
            $existing = collect($service->listWebhooks())
                ->first(fn (array $hook) => ($hook['cnpj'] ?? null) === $cnpj && ($hook['event'] ?? null) === 'nfe');

            if ($existing) {
                // Webhook já existe na Focus com um segredo que não temos como recuperar
                // (a API não retorna o valor de authorization) — não sobrescreve
                // webhook_token local pra não dessincronizar do que a Focus vai enviar.
                $config->focus_nfe_webhook_id = $existing['id'] ?? null;

                return;
            }

            if (! $config->webhook_token) {
                $config->webhook_token = Str::random(40);
            }

            $response = $service->createWebhook([
                'event' => 'nfe',
                'url' => route('webhook.fiscal', absolute: true),
                'cnpj' => $cnpj,
                'authorization_header' => 'x-focus-nfe-token',
                'authorization' => $config->webhook_token,
            ]);

            $config->focus_nfe_webhook_id = $response['id'] ?? null;
        } catch (\Throwable $e) {
            Log::channel('fiscal')->warning('Focus NFe: falha ao registrar webhook', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function baseUrlForRegistration(): string
    {
        // /v2/empresas é gestão de conta (cadastro do CNPJ emissor), não emissão de nota —
        // só existe no domínio de produção da Focus NFe, mesmo pra registrar uma empresa
        // que vai emitir em homologação. Retorna 404 se chamado em homologacao.focusnfe.com.br.
        return config('fiscal.focus_nfe.base_url_producao');
    }

    private function mapFocusErrorsToForm(Company $company, FocusNfeCompanyRegistrationException $e): void
    {
        $message = $e->getMessage();
        $lower = mb_strtolower($message);

        if (str_contains($lower, 'senha') || str_contains($lower, 'certificado')) {
            $this->addError('certificateFile', $message);
        } else {
            $this->addError('enabled', $message);
        }

        Log::channel('fiscal')->warning('Focus NFe: erro ao registrar empresa', [
            'company_id' => $company->id,
            'status_code' => $e->statusCode,
            'message' => $message,
            'errors' => $e->errors,
            'raw_response' => $e->rawResponse,
        ]);
    }

    public function render()
    {
        return view('livewire.admin.fiscal.config')
            ->layout('layouts.app', ['title' => 'Configurações Fiscais']);
    }
}
