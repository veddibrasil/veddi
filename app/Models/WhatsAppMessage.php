<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    use BelongsToCompany, HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    /** Status em que a Meta já aceitou a mensagem — não pode ser reenviada. */
    public const DELIVERED_STATUSES = [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ];

    public const STATUS_LABELS = [
        self::STATUS_QUEUED => 'Na fila',
        self::STATUS_SENT => 'Enviada',
        self::STATUS_DELIVERED => 'Entregue',
        self::STATUS_READ => 'Lida',
        self::STATUS_FAILED => 'Falhou',
    ];

    /** Código de erro da Meta → explicação para o restaurante (o texto original da Meta é técnico e em inglês). */
    private const ERROR_MESSAGES = [
        '130429' => 'A Meta limitou momentaneamente a vazão de envios do número. Tente de novo mais tarde.',
        '131026' => 'Mensagem não entregue: o cliente pode não ter WhatsApp neste número, ou ainda não aceitou os termos do WhatsApp.',
        '131030' => 'O número do cliente não está na lista de destinatários permitidos (conta em modo de teste).',
        '131031' => 'A conta do WhatsApp Business foi bloqueada pela Meta. Verifique o WhatsApp Manager.',
        '131042' => 'Problema de pagamento na conta do WhatsApp Business. Ajuste a forma de pagamento no WhatsApp Manager.',
        '131048' => 'A Meta limitou os envios do número por suspeita de spam ou baixa qualidade.',
        '131049' => 'A Meta optou por não entregar a mensagem para preservar a experiência do cliente.',
        '131050' => 'O cliente pediu para não receber mensagens da empresa.',
        '131056' => 'Muitas mensagens para o mesmo cliente em pouco tempo. A Meta bloqueou este envio.',
        '132000' => 'O modelo de mensagem recebeu variáveis em quantidade diferente da esperada.',
        '132001' => 'O modelo de mensagem não existe ou ainda não foi aprovado pela Meta.',
        '132012' => 'O modelo de mensagem recebeu variáveis em formato inválido.',
        '132015' => 'O modelo de mensagem está pausado pela Meta por baixa qualidade.',
        '132016' => 'O modelo de mensagem foi desativado pela Meta.',
        '133010' => 'O número do WhatsApp da empresa não está registrado na Meta. Reconecte o número.',
        '190' => 'A autorização do WhatsApp expirou ou foi revogada. Reconecte o número.',
        '200' => 'A autorização do WhatsApp não tem permissão para enviar mensagens. Reconecte o número.',
    ];

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'company_id',
        'whatsapp_connection_id',
        'order_id',
        'event',
        'template',
        'to_phone',
        'wamid',
        'status',
        'error_code',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function wasAccepted(): bool
    {
        return in_array($this->status, self::DELIVERED_STATUSES, true);
    }

    public function eventLabel(): string
    {
        return WhatsAppTemplate::eventLabel((string) $this->event);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    /** Erro em português para exibir ao restaurante, ou null se a mensagem não falhou. */
    public function friendlyError(): ?string
    {
        if ($this->status !== self::STATUS_FAILED) {
            return null;
        }

        return self::describeError($this->error_code, $this->error_message);
    }

    /**
     * Sem código: o texto é nosso (já em português). Com código desconhecido: não repassa o texto
     * cru da Meta, só o código, que o suporte sabe procurar.
     */
    public static function describeError(?string $code, ?string $message = null): string
    {
        if (filled($code)) {
            return self::ERROR_MESSAGES[$code] ?? "Falha no envio informada pela Meta (código {$code}).";
        }

        return filled($message) ? $message : 'Não foi possível enviar a mensagem.';
    }
}
