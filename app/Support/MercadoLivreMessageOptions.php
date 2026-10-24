<?php

namespace App\Support;

final class MercadoLivreMessageOptions
{
    /** Somente opcoes explicitamente retornadas pela API podem ser utilizadas. */
    public static function fromGuide(array $guide, array $caps): array
    {
        $options = $guide['options'] ?? (array_is_list($guide) ? $guide : []);
        $capOptions = $caps['options'] ?? (array_is_list($caps) ? $caps : []);
        $capsById = [];
        foreach (is_array($capOptions) ? $capOptions : [] as $cap) {
            if (is_array($cap)) {
                $capsById[(string) ($cap['id'] ?? $cap['option_id'] ?? '')] = $cap['cap_available'] ?? null;
            }
        }

        $result = [];
        foreach (is_array($options) ? $options : [] as $option) {
            if (!is_array($option)) {
                continue;
            }
            $id = (string) ($option['id'] ?? $option['option_id'] ?? '');
            if (!in_array($id, ['OTHER', 'REQUEST_VARIANTS'], true)) {
                continue;
            }

            $limits = array_filter([
                $capsById[$id] ?? null,
                $option['cap_available'] ?? null,
                $caps['cap_available'] ?? null,
            ], fn ($value) => is_numeric($value));
            // Cap ausente/desconhecido nao e permissao para enviar.
            $cap = $limits === [] ? null : max(0, min(array_map('intval', $limits)));
            $enabled = $cap !== null && $cap > 0 && ($option['enabled'] ?? true) !== false;
            $base = [
                'id' => $id,
                'label' => $id === 'OTHER' ? 'Outros motivos' : 'Solicitar dados de personalizacao',
                'cap_available' => $cap,
                'enabled' => $enabled,
                'disabled_reason' => $enabled ? null : (($option['enabled'] ?? true) === false
                    ? 'O Mercado Livre desabilitou este motivo.'
                    : ($cap === null
                        ? 'O Mercado Livre nao informou o limite disponivel para este motivo.'
                        : 'Sem mensagens disponiveis para este motivo. Aguarde o comprador.')),
            ];

            if ($id === 'OTHER') {
                $limit = is_numeric($option['char_limit'] ?? null) ? (int) $option['char_limit'] : 350;
                $result[] = [...$base, 'type' => 'text', 'char_limit' => max(0, min(350, $limit)), 'template_id' => null, 'text' => ''];
                continue;
            }

            $templates = $option['templates'] ?? [];
            if (isset($option['template_id'])) {
                $templates = [['id' => $option['template_id'], 'text' => $option['text'] ?? '']];
            }
            foreach (is_array($templates) ? $templates : [] as $template) {
                if (!is_array($template)) {
                    continue;
                }
                $templateId = trim((string) ($template['id'] ?? $template['template_id'] ?? ''));
                $text = is_string($template['text'] ?? null) ? trim($template['text']) : '';
                if ($templateId === '' || $text === '') {
                    continue;
                }
                $result[] = [...$base, 'type' => 'template', 'char_limit' => null, 'template_id' => $templateId, 'text' => $text];
            }
        }

        return $result;
    }
}
