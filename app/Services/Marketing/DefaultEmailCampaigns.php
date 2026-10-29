<?php

namespace App\Services\Marketing;

use App\Models\EmailCampaign;
use App\Models\User;
use InvalidArgumentException;

/**
 * Campanhas prontas: combinação de template padrão + segmento padrão (instalados se faltarem).
 * Criar uma gera um rascunho comum, que passa pelo wizard (público, teste e checklist) como
 * qualquer outra campanha. Nada é disparado daqui.
 */
class DefaultEmailCampaigns
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly DefaultEmailTemplates $templates,
        private readonly DefaultEmailSegments $segments,
    ) {}

    /** @return array<string, array{name: string, description: string, template: string, segment: string}> */
    public function definitions(): array
    {
        return [
            'upgrade_free' => [
                'name' => 'Upgrade do plano Grátis',
                'description' => 'Mostra o que muda no Essencial e no PRO para quem está no Grátis.',
                'template' => 'Upgrade do plano Grátis',
                'segment' => 'Plano Grátis ativo',
            ],
            'sales_tips' => [
                'name' => 'Dicas para vender mais',
                'description' => 'Fotos, cupons, adicionais e relatórios para os donos das empresas ativas.',
                'template' => 'Dicas para vender mais',
                'segment' => 'Donos de empresas ativas',
            ],
            'reengagement' => [
                'name' => 'Reengajar quem parou de vender',
                'description' => 'Checklist da loja para empresas ativas sem pedidos há 30 dias.',
                'template' => 'Empresa sem pedidos: reengajamento',
                'segment' => 'Sem pedidos há 30 dias',
            ],
            'pending_signup' => [
                'name' => 'Recuperar cadastros pendentes',
                'description' => 'Para quem cadastrou e não pagou a ativação, inclusive cadastros antigos.',
                'template' => 'Cadastro pendente: lembrete',
                'segment' => 'Cadastro não concluído',
            ],
            'pdv' => [
                'name' => 'Apresentar o PDV',
                'description' => 'Vendas no balcão e nas mesas para quem ainda não usa o PDV.',
                'template' => 'Conheça o PDV',
                'segment' => 'Ativos sem PDV',
            ],
            'fiscal' => [
                'name' => 'Apresentar a nota fiscal',
                'description' => 'Emissão de NFC-e para quem ainda não tem o módulo Fiscal.',
                'template' => 'Nota fiscal pelo Veddi',
                'segment' => 'Ativos sem nota fiscal',
            ],
            'launch' => [
                'name' => 'Lançamento de novidade',
                'description' => 'Anuncie um recurso novo. Tem trechos [EDITAR] para preencher.',
                'template' => 'Lançamento de novidade',
                'segment' => 'Todos os usuários de empresas ativas',
            ],
            'notice' => [
                'name' => 'Comunicado importante',
                'description' => 'Manutenção, mudança de regra ou prazo. Tem trechos [EDITAR] para preencher.',
                'template' => 'Comunicado importante',
                'segment' => 'Todos os usuários de empresas ativas',
            ],
            'nps' => [
                'name' => 'Pesquisa de satisfação',
                'description' => 'Nota de 0 a 10 por resposta ao e-mail. Configure o MARKETING_REPLY_TO.',
                'template' => 'Pesquisa de satisfação',
                'segment' => 'Donos de empresas ativas',
            ],
        ];
    }

    /** Cria o rascunho da campanha pronta, instalando template e segmento se faltarem. */
    public function createDraft(string $key, User $actor): EmailCampaign
    {
        $definition = $this->definitions()[$key] ?? throw new InvalidArgumentException('Campanha pronta desconhecida.');
        $template = $this->templates->findOrInstall($definition['template'], $actor->id);
        $segment = $this->segments->findOrInstall($definition['segment'], $actor->id);

        if (! $template || ! $segment) {
            throw new InvalidArgumentException('Modelo de template ou segmento não encontrado.');
        }

        return $this->campaigns->saveDraft(null, [
            'name' => $definition['name'].' · '.now()->format('d/m/Y'),
            'subject' => (string) $template->subject_default,
            'preheader' => $template->preheader_default,
            'from_name' => (string) config('marketing.from.name'),
            'from_email' => (string) config('marketing.from.address'),
            'reply_to' => config('marketing.reply_to') ?: null,
            'template_id' => $template->id,
            'segment_id' => $segment->id,
            'filters' => null,
            'blocks' => $template->blocks ?? [],
        ], $actor);
    }
}
