<?php

namespace App\Models;

use App\DTOs\AudienceFilterData;
use App\Enums\EmailCampaignStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Campanha de e-mail marketing. kind=automation é o envio de um passo de automação:
 * fica em "sending" enquanto a automação está ativa e não aparece na lista de campanhas.
 */
class EmailCampaign extends Model
{
    use HasFactory;

    public const KIND_CAMPAIGN = 'campaign';

    public const KIND_AUTOMATION = 'automation';

    protected $fillable = [
        'name',
        'kind',
        'subject',
        'preheader',
        'from_name',
        'from_email',
        'reply_to',
        'template_id',
        'segment_id',
        'filters',
        'blocks',
        'html',
        'html_snapshot',
        'status',
        'scheduled_at',
        'started_at',
        'finished_at',
        'test_sent_at',
        'last_error',
        'recipients_count',
        'sent_count',
        'delivered_count',
        'opened_count',
        'clicked_count',
        'bounced_count',
        'complained_count',
        'unsubscribed_count',
        'failed_count',
        'ab_subject_b',
        'ab_sample_percent',
        'ab_wait_hours',
        'ab_winner',
        'ab_decided_at',
        'created_by',
    ];

    protected $attributes = [
        'kind' => self::KIND_CAMPAIGN,
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailCampaignStatus::class,
            'filters' => 'array',
            'blocks' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'test_sent_at' => 'datetime',
            'ab_decided_at' => 'datetime',
            'ab_sample_percent' => 'integer',
            'ab_wait_hours' => 'integer',
        ];
    }

    public function scopeCampaigns(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_CAMPAIGN);
    }

    public function isAutomation(): bool
    {
        return $this->kind === self::KIND_AUTOMATION;
    }

    public function hasAbTest(): bool
    {
        return filled($this->ab_subject_b) && (int) $this->ab_sample_percent > 0;
    }

    public function subjectForVariant(?string $variant): string
    {
        $variant ??= $this->ab_winner;

        return $variant === 'b' && filled($this->ab_subject_b) ? $this->ab_subject_b : $this->subject;
    }

    /** Público efetivo: o segmento salvo, ou os filtros escolhidos na própria campanha. */
    public function audienceFilter(): AudienceFilterData
    {
        if ($this->segment_id && $this->segment) {
            return $this->segment->filterData();
        }

        return AudienceFilterData::fromArray($this->filters ?? []);
    }

    public function rate(string $counter, string $base = 'delivered_count'): float
    {
        $denominator = (int) $this->{$base} ?: (int) $this->sent_count;

        return $denominator > 0 ? round(((int) $this->{$counter} / $denominator) * 100, 1) : 0.0;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(EmailSegment::class, 'segment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'campaign_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class, 'campaign_id');
    }

    public function automationStep(): HasOne
    {
        return $this->hasOne(EmailAutomationStep::class, 'campaign_id');
    }
}
