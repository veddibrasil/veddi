<?php

namespace App\Services\Marketing;

use App\Enums\EmailSuppressionReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Limpeza da lista: regras prontas que acham, entre os usuários das empresas (público possível do
 * e-mail marketing), endereços que só geram bounce ou ninguém lê. Suprimir vira supressão manual
 * comum (removível com motivo). Cada e-mail cai na primeira regra que bater.
 */
class SuppressionCleanup
{
    private const TYPO_DOMAINS = [
        'gmail.con', 'gmail.cm', 'gmail.om', 'gmail.comm', 'gmail.coom', 'gmail.com.com', 'gmial.com', 'gmai.com',
        'gamil.com', 'gnail.com', 'gmaill.com', 'gmal.com', 'hotmail.con', 'hotmail.comm', 'hotmail.com.com',
        'hotmal.com', 'hotmial.com', 'hotmai.com', 'homail.com', 'hotmil.com', 'outlok.com', 'outlook.con',
        'outloo.com', 'yahoo.con', 'yaho.com', 'yahooo.com', 'yahoo.com.b', 'icloud.con', 'iclould.com',
        'live.con', 'uol.con', 'bol.con', 'terra.con',
    ];

    private const TEST_DOMAINS = [
        'example.com', 'example.org', 'example.net', 'test.com', 'teste.com', 'test.com.br', 'teste.com.br',
        'exemplo.com', 'exemplo.com.br', 'dominio.com', 'dominio.com.br', 'seudominio.com', 'seuemail.com', 'localhost',
    ];

    private const TEST_SUFFIXES = ['.test', '.example', '.invalid', '.localhost', '.local'];

    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'yopmail.com', 'guerrillamail.com', 'sharklasers.com', 'grr.la', '10minutemail.com',
        'temp-mail.org', 'tempmail.com', 'tempmailo.com', 'trashmail.com', 'getnada.com', 'maildrop.cc',
        'dispostable.com', 'throwawaymail.com', 'emailondeck.com', 'mohmal.com', 'fakeinbox.com', 'discard.email',
        'mailnesia.com', 'mintemail.com', 'tempr.email',
    ];

    private const AUTOMATIC_LOCAL_PARTS = [
        'noreply', 'no-reply', 'no.reply', 'donotreply', 'do-not-reply', 'naoresponda', 'nao-responda',
        'nao.responda', 'naoresponder', 'nao-responder', 'mailer-daemon', 'postmaster', 'bounce', 'bounces',
    ];

    public function __construct(private readonly SuppressionService $suppressions) {}

    /** @return array<string, array{label: string, description: string}> */
    public function rules(): array
    {
        return [
            'invalid' => [
                'label' => 'Endereço inválido',
                'description' => 'Formato que não é e-mail: o envio sempre falha.',
            ],
            'typo' => [
                'label' => 'Domínio digitado errado',
                'description' => 'Ex.: gmail.con, hotmal.com. Volta como bounce e pesa na reputação. Se souber o certo, corrija o e-mail do usuário.',
            ],
            'test' => [
                'label' => 'E-mail de teste',
                'description' => 'Domínios de exemplo ou reservados (example.com, teste.com, .test, .local).',
            ],
            'disposable' => [
                'label' => 'E-mail temporário',
                'description' => 'Caixas descartáveis (mailinator, yopmail, 10minutemail e parecidos).',
            ],
            'automatic' => [
                'label' => 'Endereço automático',
                'description' => 'noreply@, nao-responda@, postmaster@: ninguém lê.',
            ],
        ];
    }

    /** @return array<string, list<string>> e-mails (normalizados) ainda não suprimidos, por regra */
    public function scan(): array
    {
        $found = array_fill_keys(array_keys($this->rules()), []);

        foreach ($this->candidateEmails() as $email) {
            if ($rule = $this->ruleFor($email)) {
                $found[$rule][] = $email;
            }
        }

        return $found;
    }

    /** Suprime os e-mails da regra (recalculados aqui, nunca vindos da tela). Retorna quantos entraram. */
    public function suppress(string $rule, User $actor): int
    {
        $label = $this->rules()[$rule]['label'] ?? throw new InvalidArgumentException('Regra de limpeza desconhecida.');
        $added = 0;

        foreach ($this->scan()[$rule] as $email) {
            if ($this->suppressions->suppress($email, EmailSuppressionReason::Manual, null, 'Limpeza da lista: '.$label, $actor->id)->wasRecentlyCreated) {
                $added++;
            }
        }

        Log::channel('marketing')->info('Marketing: limpeza da lista de supressão', ['rule' => $rule, 'added' => $added, 'actor_id' => $actor->id]);

        return $added;
    }

    public function ruleFor(string $email): ?string
    {
        $email = SuppressionService::normalize($email);

        if (! SuppressionService::isValidEmail($email)) {
            return 'invalid';
        }

        [$local, $domain] = explode('@', $email, 2);

        return match (true) {
            in_array($domain, self::TYPO_DOMAINS, true) => 'typo',
            in_array($domain, self::TEST_DOMAINS, true),
            collect(self::TEST_SUFFIXES)->contains(fn (string $suffix) => str_ends_with($domain, $suffix)) => 'test',
            in_array($domain, self::DISPOSABLE_DOMAINS, true) => 'disposable',
            in_array(explode('+', $local, 2)[0], self::AUTOMATIC_LOCAL_PARTS, true) => 'automatic',
            default => null,
        };
    }

    /**
     * E-mails dos usuários das empresas (papéis do público) e das próprias empresas, sem os já
     * suprimidos. Consulta direta em company_user/users/companies como o AudienceResolver: o
     * módulo é da plataforma e cruza empresas.
     *
     * @return list<string>
     */
    private function candidateEmails(): array
    {
        $users = DB::table('company_user')
            ->join('users', 'users.id', '=', 'company_user.user_id')
            ->whereNull('users.deleted_at')
            ->whereIn('company_user.role', config('marketing.roles', []))
            ->whereNotExists(fn ($suppressions) => $suppressions->selectRaw('1')
                ->from('email_suppressions')
                ->whereRaw('email_suppressions.email = LOWER(TRIM(users.email))'))
            ->distinct()
            ->pluck('users.email');

        $companies = DB::table('companies')
            ->whereRaw("TRIM(COALESCE(companies.email, '')) <> ''")
            ->whereNotExists(fn ($suppressions) => $suppressions->selectRaw('1')
                ->from('email_suppressions')
                ->whereRaw('email_suppressions.email = LOWER(TRIM(companies.email))'))
            ->distinct()
            ->pluck('companies.email');

        return $users->concat($companies)
            ->map(fn (string $email) => SuppressionService::normalize($email))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
