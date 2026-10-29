<?php

namespace App\Livewire\SuperAdmin\Marketing\Concerns;

use App\Services\Marketing\TemplateRenderer;
use Livewire\Attributes\Computed;

/**
 * Editor de blocos do e-mail (templates e campanhas). O HTML final é sempre gerado e
 * sanitizado no servidor pelo TemplateRenderer; o navegador só edita os campos dos blocos.
 */
trait EditsEmailBlocks
{
    public array $blocks = [];

    public ?int $previewCompanyId = null;

    public string $previewDevice = 'desktop';

    public function addBlock(string $type): void
    {
        if (! array_key_exists($type, TemplateRenderer::BLOCK_TYPES) || count($this->blocks) >= 30) {
            return;
        }

        $this->blocks[] = TemplateRenderer::newBlock($type);
    }

    public function removeBlock(int $index): void
    {
        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);
    }

    public function moveBlock(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->blocks[$index], $this->blocks[$target])) {
            return;
        }

        [$this->blocks[$index], $this->blocks[$target]] = [$this->blocks[$target], $this->blocks[$index]];
    }

    public function setPreviewDevice(string $device): void
    {
        $this->previewDevice = $device === 'mobile' ? 'mobile' : 'desktop';
    }

    #[Computed]
    public function previewHtml(): string
    {
        $renderer = app(TemplateRenderer::class);

        return $renderer->preview(
            body: $renderer->blocksToHtml($this->blocks),
            preheader: $this->previewPreheader(),
            variables: $renderer->sampleVariables($this->previewCompanyId),
        );
    }

    protected function previewPreheader(): ?string
    {
        return null;
    }

    /** @return array<string, array<int, string>> */
    protected function blockRules(): array
    {
        return [
            'blocks' => ['array', 'max:30'],
            'blocks.*.type' => ['required', 'in:'.implode(',', array_keys(TemplateRenderer::BLOCK_TYPES))],
            'blocks.*.title' => ['nullable', 'string', 'max:200'],
            'blocks.*.html' => ['nullable', 'string', 'max:20000'],
            'blocks.*.text' => ['nullable', 'string', 'max:1000'],
            'blocks.*.alt' => ['nullable', 'string', 'max:200'],
            'blocks.*.label' => ['nullable', 'string', 'max:80'],
            'blocks.*.url' => ['nullable', 'string', 'max:2048'],
            'blocks.*.link' => ['nullable', 'string', 'max:2048'],
            'blocks.*.logo_url' => ['nullable', 'string', 'max:2048'],
            'blocks.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /** @return list<string> */
    protected function unknownBlockVariables(string ...$extra): array
    {
        $texts = $extra;

        foreach ($this->blocks as $block) {
            foreach (['title', 'html', 'text', 'label', 'alt'] as $field) {
                $texts[] = (string) ($block[$field] ?? '');
            }
        }

        return app(TemplateRenderer::class)->unknownVariables(...$texts);
    }
}
