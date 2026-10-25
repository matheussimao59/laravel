<?php

namespace App\Support;

final class MlPersonalizationExtractor
{
    /**
     * Pares implicitos "Matheus 8 anos": qualquer nome curto (letras, acentos,
     * apostrofo, hifen) seguido da idade, aceitando separador (",", "/", "-"),
     * a conjuncao "e" entre as pessoas e o rotulo "Nome:"/"Idade:".
     */
    private const PAIR_SCAN = "~(?<![\\p{L}\\p{N}])([\\p{L}\\p{M}'’ .-]{0,40}?)\\s*[,·/-]?\\s*(\\d{1,3})(?![\\d/-])\\s*(anos?|aninhos?|mes(?:es)?|mesinhos?)\\b~iu";

    /** Gap aceito entre um par e o proximo (espacos, separadores, "e" ou rotulo "Nome:"). */
    private const PAIR_GAP = "~^\\s*(?:[,;:|·/-]\\s*)*(?:e\\s+)?(?:o\\s+)?(?:nome(?:\\s+(?:da crian[çc]a|do aniversariante))?\\s*[:=]\\s*)?$~iu";

    /** Idade antes do nome: "9 anos de Lara", "8 anos pra Bea".Nome curto com inicial maiuscula. */
    private const REVERSE_SCAN = "~(?<![\\p{L}\\p{N}])(\\d{1,3})(?![\\d/-])\\s*(anos?|aninhos?|mes(?:es)?|mesinhos?)\\s+(?:de|do|da|pra|para|ou)\\s+([\\p{L}\\p{M}][\\p{L}\\p{M}'’ .-]{0,40}?)(?=$|[,;.:!?])~iu";

    /** Palavras de preenchimento aceitaveis antes do primeiro par ("Bom dia, quero Maria 8 anos"). */
    private const FILLER = "~^(?:bom|boa|dia|tarde|noite|ola|oi|obrigad[oa]|quero|preciso|gostaria|pedido|nome|pra|para|por|favor|de|do|da|dos|das|o|a|os|as|é|eh|meu|minha|filh[oa]|filh[oa]s|m[ãa]e|pai|tio|tia|prim[oa]|prim[oa]s|net[oa]|sobrinh[oa]|aniversariante|crian[çc]a|bebê|bebe|presente|presentes|adicionar|seria|posso|pode|colocar|coloque|e|um|uma|dois|duas|sim|tudo|bem|vai|faz|entao|então)$~iu";

    /** Nome rotulado; encerra antes de pontuacao, nova linha ou do rotulo idade. */
    private const NAME_LABEL = "~(?:^|[\\n;,|])\\s*(?:o\\s+)?nome(?:\\s+(?:da crian[çc]a|do aniversariante))?\\s*(?::|=|é|eh)\\s*([\\p{L}\\p{M}][\\p{L}\\p{M}'’ .-]{0,110}?)(?=\\s*(?:[|,;\\n]|(?:e\\s+)?idade\\b|$))~iu";

    private const AGE_LABEL = '~(?<!\p{L})idade\s*[:=]?\s*(\d{1,3})(?![\d/.-])[\t ]*(?:(anos?|aninhos?|mes(?:es)?|mesinhos?)\b|(?=[\t ]*(?:$|[,;|\n!?]|nome\b)))~iu';

    /** Regras locais e conservadoras: nao envia mensagens nem dados a terceiros. */
    public function extract(array $messages): array
    {
        $names = []; $ages = []; $evidence = []; $question = false; $correction = false; $optOut = false;
        $labeledNames = []; $labeledAges = []; $implicitPairs = []; $looseValues = [];

        foreach ($messages as $message) {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($message['text'] ?? '')));
            if ($text === '') continue;
            $hasData = false; $snippets = [];
            if (str_contains($text, '?') && preg_match('~\b(?:nome|idade|anos?|meses)\b~iu', $text)) $question = true;
            if (preg_match('~\b(?:sem\s+(?:nome|idade|personaliza[çc][aã]o)|n[aã]o\s+(?:quero|precisa|colocar|personalizar))\b~iu', $text)) $optOut = true;

            // Nome rotulado; encerra antes de pontuacao, nova linha ou do rotulo idade.
            preg_match_all(self::NAME_LABEL, $text, $nameMatches, PREG_SET_ORDER);
            foreach ($nameMatches as $match) {
                if ($name = $this->validName($match[1])) {
                    $names[mb_strtolower($name)] ??= $name;
                    if (!in_array($name, $labeledNames, true)) $labeledNames[] = $name;
                    $hasData = true; $snippets[] = trim($match[0]);
                }
            }
            preg_match_all(self::AGE_LABEL, $text, $ageMatches, PREG_SET_ORDER);
            foreach ($ageMatches as $match) {
                if ($age = $this->age((int) $match[1], $match[2] ?? 'anos')) {
                    $ages[$age] ??= $age;
                    if (!in_array($age, $labeledAges, true)) $labeledAges[] = $age;
                    $hasData = true; $snippets[] = trim($match[0]);
                }
            }

            // Formatos claros como "Matheus 8 anos Lara 8 anos". A quebra de linha
            // vira separador: nomes soltos em linha propria nao se juntam a idades seguintes.
            $compact = preg_replace('/\s+/u', ' ', preg_replace('/,+/', ',', str_replace("\n", ',', $text)));
            foreach ($this->scanPairs($compact) as $pair) {
                $names[mb_strtolower($pair['name'])] ??= $pair['name'];
                $ages[$pair['age']] ??= $pair['age'];
                $implicitPairs[] = $pair;
                $hasData = true; $snippets[] = "{$pair['name']} {$pair['age']}";
            }
            if (count(explode(' ', (string) $compact)) === 1 && ($looseName = $this->validName((string) $compact))) {
                if (!in_array($looseName, $looseValues, true)) $looseValues[] = $looseName; // "Matheus" numa mensagem, a idade na proxima.
            }
            foreach ($this->scanReversePairs($compact) as $pair) {
                $names[mb_strtolower($pair['name'])] ??= $pair['name'];
                $ages[$pair['age']] ??= $pair['age'];
                $implicitPairs[] = $pair;
                $hasData = true; $snippets[] = "{$pair['name']} {$pair['age']}";
            }
            // Idade explicita pode vir em mensagem separada de "Nome: ...".
            if (preg_match('~^(?:vai fazer\s+)?(\d{1,3})\s*(anos?|aninhos?|mes(?:es)?|mesinhos?)[.!]?$~iu', $compact, $ageOnly)) {
                if ($age = $this->age((int) $ageOnly[1], $ageOnly[2])) { $ages[$age] ??= $age; $hasData = true; $snippets[] = $compact; }
            }

            if ($hasData) {
                $question = $question || str_contains($text, '?');
                $evidence[] = ['id' => (string) ($message['id'] ?? ''), 'date' => $message['date'] ?? null, 'excerpt' => mb_substr(implode(' | ', array_unique($snippets)), 0, 300)];
            }
            if (preg_match('~\b(?:corrigir|corre[çc][aã]o|trocar|na verdade|n[aã]o [eé]|alterar)\b~iu', $text)) $correction = true;
        }

        // 1) pares rotulados por ordem de aparecimento; 2) pares implicitos sem repetir nome.
        $pairs = []; $seenNames = [];
        $zipLimit = min(count($labeledNames), count($labeledAges));
        for ($index = 0; $index < $zipLimit; $index++) {
            $pairs[] = ['name' => $labeledNames[$index], 'age' => $labeledAges[$index]];
            $seenNames[mb_strtolower($labeledNames[$index])] = true;
        }
        $stray = false;
        foreach ($implicitPairs as $pair) {
            $lower = mb_strtolower($pair['name']);
            $duplicate = false; $conflicting = false;
            foreach ($pairs as $existing) {
                if (mb_strtolower($existing['name']) !== $lower) continue;
                $existing['age'] === $pair['age'] ? $duplicate = true : $conflicting = true;
            }
            if ($duplicate && !$conflicting) continue; // comprador repetiu o mesmo dado.
            // Mesmo nome com idades diferentes ou terceira pessoa ficam para revisao.
            if ($conflicting || isset($seenNames[$lower]) || count($pairs) >= 2) { $stray = true; continue; }
            $pairs[] = ['name' => $pair['name'], 'age' => $pair['age']];
            $seenNames[$lower] = true;
        }
        // Nome solto (uma mensagem com o nome, a idade na proxima, sem rotulos).
        if ($pairs === [] && count($labeledNames) === 0 && count($looseValues) === 1 && count($ages) === 1) {
            $loose = $looseValues[0];
            $names[mb_strtolower($loose)] ??= $loose;
            $pairs[] = ['name' => $loose, 'age' => array_values($ages)[0]];
            $seenNames[mb_strtolower($loose)] = true;
        }
        // Nome solto em mensagem quando ja existe um par identificado: pessoa extra
        // sem idade fica para revisao (ex. "Matheus 8 anos" e depois "Lara").
        foreach ($looseValues as $value) {
            if (!isset($seenNames[mb_strtolower($value)])) { $stray = true; break; }
        }

        $name = count($names) === 1 ? array_values($names)[0] : null;
        $age = count($ages) === 1 ? array_values($ages)[0] : null;
        $clean = !$question && !$correction && !($optOut && ($names !== [] || $ages !== []));
        $multiple = $clean && count($pairs) === 2 && !$stray && count($names) <= 2 && count($ages) <= 2;
        $single = $clean && count($names) === 1 && count($ages) === 1;
        $identified = !$stray && ($single || $multiple);
        if ($multiple) { $name = null; $age = null; }
        $status = $identified ? 'identified'
            : ($optOut ? ($names !== [] || $ages !== [] ? 'review' : 'no_personalization')
            : ($names !== [] || $ages !== [] || $question || $correction ? 'review' : 'awaiting'));

        return [
            'name' => $name, 'age' => $age,
            'pairs' => $identified ? ($pairs !== [] ? $pairs : ($single && $name && $age ? [['name' => $name, 'age' => $age]] : [])) : [],
            'status' => $status,
            'note' => match ($status) {
                'identified' => $multiple
                    ? 'Dois nomes com as idades foram identificados nas mensagens do comprador.'
                    : 'Nome e idade identificados nas mensagens do comprador.',
                'no_personalization' => 'O comprador indicou que nao deseja personalizacao. Confira antes de produzir.',
                'review' => 'Dados incompletos, pergunta, correcao ou mais de uma personalizacao incerta. Revise antes de preencher a impressao.',
                default => 'Nenhum par claro de nome e idade foi identificado. Anexos e formatos livres precisam de revisao.',
            },
            'evidence' => array_slice($evidence, -10),
        ];
    }

    /**
     * Extrai pares sequenciais dentro de uma mensagem compactada. Cada novo par
     * precisa vir logo apos o anterior (apenas separadores, "e" ou rotulo "Nome:").
     * Se sobra outro nome solto depois do ultimo par, a mensagem e ambigua e
     * nenhum par e aceito (conservador).
     */
    private function scanPairs(string $compact): array
    {
        if ($compact === '' || !preg_match_all(self::PAIR_SCAN, $compact, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];

        $pairs = []; $cursor = null;
        foreach ($matches as $match) {
            $raw = $match[1][0];
            $nameOffset = (int) $match[1][1];
            $age = $this->age((int) $match[2][0], $match[3][0]);
            if ($raw === '' || $age === null || count(explode(' ', trim($raw))) > 3) continue;

            if ($cursor !== null && $nameOffset <= $cursor) continue; // sobreposto ao par anterior.
            $first = $cursor === null;
            $name = null; $origin = $nameOffset;
            $gap = $first ? substr($compact, 0, $nameOffset) : substr($compact, $cursor, $nameOffset - $cursor);
            if ($gap === '' || $this->gapAccepted($gap, $first)) $name = $this->validName($raw);
            if (!$name) {
                // Sufixo: "quero Maria 8 anos" / "e Lara 8 anos" -> nome valido no fim,
                // quando todo o prefixo sao palavras de preenchimento ou separador.
                foreach ($this->suffixes($raw, $nameOffset) as [$suffixName, $offset]) {
                    if ($origin !== $offset && $offset <= ($cursor ?? -1)) continue; // sobreposto.
                    $suffixGap = $first ? substr($compact, 0, $offset) : substr($compact, $cursor, $offset - $cursor);
                    if ($suffixGap !== '' && !$this->gapAccepted($suffixGap, $first)) continue;
                    if ($suffix = $this->validName($suffixName)) { $name = $suffix; $origin = $offset; break; }
                }
            }
            if (!$name) continue;

            $pairs[] = ['name' => $name, 'age' => $age];
            $cursor = $match[0][1] + strlen($match[0][0]);
        }

        // Depois do ultimo par aceito nao pode sobrar outro nome provavel ("Matheus
        // 8 anos Lara"): pessoa adicional sem idade deixa a mensagem ambigua.
        if ($pairs !== [] && $cursor !== null) {
            $tail = trim((string) preg_replace("~^\\s*(?:[,;:|·/-]\\s*)*(?:e\\s+)?~iu", '', substr($compact, $cursor)));
            if ($tail !== '' && $this->isPotentialName($tail)) return [];
        }

        return $pairs;
    }

    /** Sufixos nomeaveis: cada um exige prefixo feito apenas de palavras de preenchimento. */
    private function suffixes(string $raw, int $nameOffset): array
    {
        $words = preg_split('~\s+~u', $raw, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $count = count($words);
        $result = [];
        for ($index = 1; $index < $count; $index++) {
            for ($position = 0; $position < $index; $position++) {
                if (!preg_match(self::FILLER, $words[$position][0])) { $index = $count; break 2; }
            }
            $suffix = implode(' ', array_column(array_slice($words, $index), 0));
            $result[] = [$suffix, $nameOffset + $words[$index][1]];
        }

        return $result;
    }

    /** Idade antes do nome ("9 anos de Lara"); nome precisa parecer proprio. */
    private function scanReversePairs(string $compact): array
    {
        if ($compact === '' || !preg_match_all(self::REVERSE_SCAN, $compact, $matches, PREG_SET_ORDER)) return [];

        $pairs = [];
        foreach ($matches as $match) {
            $age = $this->age((int) $match[1], $match[2]);
            $name = $age === null ? null : $this->validName($match[3]);
            if (!$name || !$this->isPotentialName($name)) continue;
            $pairs[] = ['name' => $name, 'age' => $age];
        }

        return $pairs;
    }

    /** Gap aceito: rotulo/separador; antes do primeiro par, frases de abertura feitas so de palavras de preenchimento. */
    private function gapAccepted(string $gap, bool $first): bool
    {
        if (preg_match(self::PAIR_GAP, $gap)) return true;
        if (!$first) return false;

        $words = preg_split('~[\s,;·/.:\-|]+~u', trim($gap), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) return false;
        foreach ($words as $word) {
            if (!preg_match(self::FILLER, $word)) return false;
        }

        return true;
    }

    /** Nome provavel: 1-2 palavras, todas iniciadas em maiuscula e palavras validas. */
    private function isPotentialName(string $value): bool
    {
        $value = trim($value, " ");
        if ($value === '' || count(explode(' ', $value)) > 2) return false;
        foreach (explode(' ', $value) as $word) {
            if ($word === '' || mb_strtolower(mb_substr($word, 0, 1)) === mb_substr($word, 0, 1)) return false;
        }

        return $this->validName($value) !== null;
    }

    private function validName(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value), " .-\t\n");
        if ($value === '' || mb_strlen($value) > 100 || count(explode(' ', $value)) > 6) return null;
        if (preg_match("~\b(?:bom|dia|boa|tarde|noite|ola|obrigad[oa]|quero|preciso|gostaria|pedido|idade|sem|nao|não|apenas|seria|pode|colocar|pra|presente|presentes|vamos|entao|então|e|meu|minha|filh[oa]|filh[oa]s|m[ãa]e|pai|tio|tia|prim[oa]|prim[oa]s|net[oa]|net[oa]s|sobrinh[oa]|aniversariante|crian[çc]a|bebê|bebe|ela|ele|tem|para|um|uma|dois|duas|tres|três|quatro|cinco|seis|sete|oito|nove|dez|onze|doze|sim|tudo|bem|anos?|mes(?:es)?|mesinhos?)\b~iu", $value)) return null;
        if (!preg_match("~^[\p{L}\p{M}][\p{L}\p{M}'’ .-]*$~u", $value)) return null;

        return $value;
    }

    private function age(int $number, string $unit): ?string
    {
        $months = str_starts_with(mb_strtolower($unit), 'mes');
        if ($number > ($months ? 1440 : 120)) return null;

        return $number . ($months ? ($number === 1 ? ' mes' : ' meses') : ($number === 1 ? ' ano' : ' anos'));
    }
}
