<?php

namespace App\Support;

final class MlPersonalizationExtractor
{
    /** Regras locais e conservadoras: nao envia mensagens nem dados a terceiros. */
    public function extract(array $messages): array
    {
        $names = []; $ages = []; $evidence = []; $question = false; $correction = false; $optOut = false;
        foreach ($messages as $message) {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($message['text'] ?? '')));
            if ($text === '') continue;
            $hasData = false; $snippets = [];
            if (str_contains($text, '?') && preg_match('~\b(?:nome|idade|anos?|meses)\b~iu', $text)) $question = true;
            if (preg_match('~\b(?:sem\s+(?:nome|idade|personaliza[çc][aã]o)|n[aã]o\s+(?:quero|precisa|colocar|personalizar))\b~iu', $text)) $optOut = true;

            // Nome rotulado; encerra antes de pontuacao, nova linha ou do rotulo idade.
            preg_match_all("~(?:^|[\\n;,|])\\s*(?:o\\s+)?nome(?:\\s+(?:da crian[çc]a|do aniversariante))?\\s*(?::|=|é|eh)\\s*([\\p{L}\\p{M}][\\p{L}\\p{M}'’ .-]{0,110}?)(?=\\s*(?:[|,;\\n]|(?:e\\s+)?idade\\b|$))~iu", $text, $nameMatches);
            foreach ($nameMatches[1] as $index => $candidate) {
                if ($name = $this->validName($candidate)) { $names[mb_strtolower($name)] = $name; $hasData = true; $snippets[] = trim($nameMatches[0][$index]); }
            }
            preg_match_all('~(?<!\p{L})idade\s*[:=]?\s*(\d{1,3})(?![\d/.-])[\t ]*(?:(anos?|aninhos?|mes(?:es)?|mesinhos?)\b|(?=[\t ]*(?:$|[,;|\n!?]|nome\b)))~iu', $text, $ageMatches, PREG_SET_ORDER);
            foreach ($ageMatches as $match) {
                if ($age = $this->age((int) $match[1], $match[2] ?? 'anos')) { $ages[$age] = $age; $hasData = true; $snippets[] = trim($match[0]); }
            }
            // Formatos claros como "Maria Clara, 5 anos", inclusive em duas linhas.
            $compact = preg_replace('/\s+/u', ' ', $text);
            if (preg_match("~^([\\p{L}\\p{M}][\\p{L}\\p{M}'’ .-]{0,110}?)(?:\\s*[,/-]\\s*|\\s+)(\\d{1,3})\\s*(anos?|aninhos?|mes(?:es)?|mesinhos?)[.!]?$~iu", $compact, $pair)) {
                $name = $this->validName($pair[1]); $age = $this->age((int) $pair[2], $pair[3]);
                if ($name && $age) { $names[mb_strtolower($name)] = $name; $ages[$age] = $age; $hasData = true; $snippets[] = $compact; }
            }
            // Idade explicita pode vir em mensagem separada de "Nome: ...".
            if (preg_match('~^(?:vai fazer\s+)?(\d{1,3})\s*(anos?|aninhos?|mes(?:es)?|mesinhos?)[.!]?$~iu', $compact, $ageOnly)) {
                if ($age = $this->age((int) $ageOnly[1], $ageOnly[2])) { $ages[$age] = $age; $hasData = true; $snippets[] = $compact; }
            }
            if ($hasData) {
                $question = $question || str_contains($text, '?');
                $evidence[] = ['id' => (string) ($message['id'] ?? ''), 'date' => $message['date'] ?? null, 'excerpt' => mb_substr(implode(' | ', array_unique($snippets)), 0, 300)];
            }
            if (preg_match('~\b(?:corrigir|corre[çc][aã]o|trocar|na verdade|n[aã]o [eé]|alterar)\b~iu', $text)) $correction = true;
        }
        $name = count($names) === 1 ? array_values($names)[0] : null;
        $age = count($ages) === 1 ? array_values($ages)[0] : null;
        $conflict = count($names) > 1 || count($ages) > 1 || $question || $correction || ($optOut && ($names !== [] || $ages !== []));
        $status = $conflict ? 'review' : ($optOut ? 'no_personalization' : ($name && $age ? 'identified' : ($names !== [] || $ages !== [] ? 'review' : 'awaiting')));

        return [
            'name' => $name, 'age' => $age, 'status' => $status,
            'note' => match ($status) {
                'identified' => 'Nome e idade identificados nas mensagens do comprador.',
                'no_personalization' => 'O comprador indicou que nao deseja personalizacao. Confira antes de produzir.',
                'review' => 'Dados incompletos, pergunta, correcao ou mais de uma personalizacao. Revise antes de preencher a impressao.',
                default => 'Nenhum par claro de nome e idade foi identificado. Anexos e formatos livres precisam de revisao.',
            },
            'evidence' => array_slice($evidence, -10),
        ];
    }

    private function validName(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value), " .-\t\n");
        if ($value === '' || mb_strlen($value) > 100 || count(explode(' ', $value)) > 6) return null;
        if (preg_match('~\b(?:bom|dia|boa|tarde|noite|ola|obrigad[oa]|quero|preciso|pedido|idade|sem|nao|não|apenas|seria|pode|colocar|e|meu|minha|filh[oa]|m[ãa]e|pai|ela|ele|tem|para)\b~iu', $value)) return null;
        if (!preg_match("~^[\\p{L}\\p{M}][\\p{L}\\p{M}'’ .-]*$~u", $value)) return null;

        return $value;
    }

    private function age(int $number, string $unit): ?string
    {
        $months = str_starts_with(mb_strtolower($unit), 'mes');
        if ($number > ($months ? 1440 : 120)) return null;

        return $number . ($months ? ($number === 1 ? ' mes' : ' meses') : ($number === 1 ? ' ano' : ' anos'));
    }
}
