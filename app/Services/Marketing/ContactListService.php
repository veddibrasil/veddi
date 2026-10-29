<?php

namespace App\Services\Marketing;

use App\Models\EmailContactList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Listas de contatos externos (possíveis clientes). Toda lista nasce com a origem declarada e o
 * aceite confirmado: o provedor (Resend) só permite envio para quem pediu para receber.
 * Importa CSV ou texto colado; endereço que só geraria bounce (inválido, domínio errado, teste,
 * temporário, automático) fica de fora para não pesar na reputação do domínio.
 */
class ContactListService
{
    private const EMAIL_HEADERS = ['email', 'e-mail', 'e_mail', 'mail'];

    private const NAME_HEADERS = ['nome', 'name', 'contato', 'responsavel', 'responsável'];

    private const COMPANY_HEADERS = ['empresa', 'company', 'restaurante', 'estabelecimento', 'loja', 'nome fantasia', 'razao social', 'razão social'];

    private const INSERT_CHUNK = 500;

    public function __construct(private readonly SuppressionCleanup $cleanup) {}

    public function create(string $name, string $source, User $actor): EmailContactList
    {
        $list = EmailContactList::create([
            'name' => trim($name),
            'source' => trim($source),
            'consent_confirmed_at' => now(),
            'created_by' => $actor->id,
        ]);

        Log::channel('marketing')->info('Marketing: lista de contatos criada', ['list_id' => $list->id, 'actor_id' => $actor->id]);

        return $list;
    }

    /**
     * Arquivo CSV (separador , ; ou tab). Com cabeçalho, acha as colunas e-mail, nome e empresa
     * pelo nome; sem cabeçalho, a ordem é essa.
     *
     * @return array{added: int, existing: int, invalid: int, skipped: int, duplicates: int}
     */
    public function importFile(EmailContactList $list, string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['added' => 0, 'existing' => 0, 'invalid' => 0, 'skipped' => 0, 'duplicates' => 0];
        }

        try {
            return $this->import($list, (function () use ($handle) {
                while (($line = fgets($handle)) !== false) {
                    yield $line;
                }
            })());
        } finally {
            fclose($handle);
        }
    }

    /**
     * Texto colado: tabela CSV ou uma lista de e-mails separados por espaço, vírgula ou ponto e vírgula.
     *
     * @return array{added: int, existing: int, invalid: int, skipped: int, duplicates: int}
     */
    public function importText(EmailContactList $list, string $text, string $format = 'table'): array
    {
        return $this->import($list, $this->textLines($text, $format));
    }

    /** Prévia sem gravar: usa o mesmo parser e as mesmas regras da importação. */
    public function previewText(EmailContactList $list, string $text, string $format = 'emails'): array
    {
        $preview = ['total' => 0, 'new' => 0, 'existing' => 0, 'duplicates' => 0, 'invalid' => 0, 'skipped' => 0, 'sample' => [], 'issues' => []];
        $contacts = [];

        foreach ($this->parseLines($this->textLines($text, $format)) as $contact) {
            $preview['total']++;
            $email = $contact['email'];
            if ($rule = $this->cleanup->ruleFor($email)) {
                $preview[$rule === 'invalid' ? 'invalid' : 'skipped']++;
                if (count($preview['issues']) < 5) {
                    $preview['issues'][] = ['email' => Str::limit($email, 150), 'reason' => $this->cleanup->rules()[$rule]['label']];
                }

                continue;
            }
            if (isset($contacts[$email])) {
                $preview['duplicates']++;

                continue;
            }
            $contacts[$email] = $contact;
        }

        $existing = [];
        foreach (array_chunk(array_keys($contacts), self::INSERT_CHUNK) as $emails) {
            foreach (DB::table('email_contacts')->where('list_id', $list->id)->whereIn('email', $emails)->pluck('email') as $email) {
                $existing[$email] = true;
            }
        }
        $preview['existing'] = count($existing);
        $preview['new'] = count($contacts) - count($existing);

        foreach (array_slice($contacts, 0, 5) as $email => $contact) {
            $preview['sample'][] = $contact + ['existing' => isset($existing[$email])];
        }

        return $preview;
    }

    private function textLines(string $text, string $format): array
    {
        return $format === 'emails'
            ? (preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : (preg_split('/\R/', $text) ?: []);
    }

    /**
     * @param  iterable<string>  $lines
     * @return array{added: int, existing: int, invalid: int, skipped: int, duplicates: int}
     */
    private function import(EmailContactList $list, iterable $lines): array
    {
        $result = ['added' => 0, 'existing' => 0, 'invalid' => 0, 'skipped' => 0, 'duplicates' => 0];
        $pending = [];
        $seen = [];

        foreach ($this->parseLines($lines) as $contact) {
            $email = $contact['email'];
            $rule = $this->cleanup->ruleFor($email);

            if ($rule !== null) {
                $result[$rule === 'invalid' ? 'invalid' : 'skipped']++;

                continue;
            }

            if (isset($seen[$email])) {
                $result['duplicates']++;

                continue;
            }
            $seen[$email] = true;
            $pending[$email] = ['name' => $contact['name'], 'company_name' => $contact['company_name']];

            if (count($pending) >= self::INSERT_CHUNK) {
                $this->insert($list, $pending, $result);
                $pending = [];
            }
        }

        $this->insert($list, $pending, $result);

        Log::channel('marketing')->info('Marketing: contatos importados', ['list_id' => $list->id] + $result);

        return $result;
    }

    /** @param iterable<string> $lines */
    private function parseLines(iterable $lines): iterable
    {
        $delimiter = null;
        $columns = null;
        foreach ($lines as $line) {
            $line = trim(ltrim((string) $line, "\u{FEFF}"));
            if ($line === '') {
                continue;
            }
            $delimiter ??= $this->detectDelimiter($line);
            $cells = array_map(fn ($cell) => trim((string) $cell), str_getcsv($line, $delimiter, '"', ''));
            if ($columns === null) {
                $columns = $this->headerColumns($cells);
                if ($columns !== null) {
                    continue;
                }
                $columns = ['email' => 0, 'name' => 1, 'company' => 2];
            }
            $email = SuppressionService::normalize($cells[$columns['email']] ?? '');
            if ($email !== '') {
                yield ['email' => $email, 'name' => $this->cell($cells, $columns['name']), 'company_name' => $this->cell($cells, $columns['company'])];
            }
        }
    }

    /** @param  array<string, array{name: ?string, company_name: ?string}>  $contacts */
    private function insert(EmailContactList $list, array $contacts, array &$result): void
    {
        if ($contacts === []) {
            return;
        }

        $now = now();
        $added = DB::table('email_contacts')->insertOrIgnore(collect($contacts)->map(fn (array $contact, string $email) => [
            'list_id' => $list->id,
            'email' => $email,
            'name' => $contact['name'],
            'company_name' => $contact['company_name'],
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());

        $result['added'] += $added;
        $result['existing'] += count($contacts) - $added;
    }

    /** @return array{email: int, name: ?int, company: ?int}|null colunas pelo cabeçalho, ou null se a linha já for contato */
    private function headerColumns(array $cells): ?array
    {
        $normalized = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), $cells);
        $find = fn (array $names) => collect($normalized)->search(fn ($cell) => in_array($cell, $names, true));

        $email = $find(self::EMAIL_HEADERS);

        if ($email === false) {
            return null;
        }

        $name = $find(self::NAME_HEADERS);
        $company = $find(self::COMPANY_HEADERS);

        return ['email' => $email, 'name' => $name === false ? null : $name, 'company' => $company === false ? null : $company];
    }

    private function detectDelimiter(string $line): string
    {
        $counts = ['tab' => substr_count($line, "\t"), ';' => substr_count($line, ';'), ',' => substr_count($line, ',')];
        $best = array_search(max($counts), $counts, true);

        return max($counts) === 0 ? ',' : ($best === 'tab' ? "\t" : $best);
    }

    private function cell(array $cells, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        $value = Str::limit(trim((string) ($cells[$index] ?? '')), 250, '');

        return $value === '' ? null : $value;
    }
}
