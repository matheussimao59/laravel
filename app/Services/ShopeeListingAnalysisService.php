<?php

namespace App\Services;

use App\Support\ExternalServiceException;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

final class ShopeeListingAnalysisService
{
    public function analyze(string $competitorUrl, string $myUrl): array
    {
        $competitor = $this->fetchListing($competitorUrl);
        $mine = $this->fetchListing($myUrl);

        $competitorScores = $this->scoresForListing($competitor, $competitor);
        $mineScores = $this->scoresForListing($mine, $competitor);

        $analysis = [
            'competitor' => $competitor,
            'mine' => $mine,
            'summary' => [
                'competitor_score' => $competitorScores['total'],
                'my_score' => $mineScores['total'],
                'gap' => max(0, $competitorScores['total'] - $mineScores['total']),
            ],
            'radar' => $this->radarRows($competitor, $mine, $competitorScores, $mineScores),
            'categories' => [
                ['id' => 'media', 'label' => 'Visual do anuncio', 'competitor' => $competitorScores['media'], 'mine' => $mineScores['media']],
                ['id' => 'title', 'label' => 'Titulo', 'competitor' => $competitorScores['title'], 'mine' => $mineScores['title']],
                ['id' => 'keywords', 'label' => 'Palavras-chave', 'competitor' => $competitorScores['keywords'], 'mine' => $mineScores['keywords']],
                ['id' => 'description', 'label' => 'Descricao', 'competitor' => $competitorScores['description'], 'mine' => $mineScores['description']],
                ['id' => 'social', 'label' => 'Prova social', 'competitor' => $competitorScores['social'], 'mine' => $mineScores['social']],
            ],
            'diagnosis' => $this->diagnosisBlocks($competitor, $mine, $competitorScores, $mineScores),
            'comparisons' => $this->comparisonRows($competitor, $mine, $competitorScores, $mineScores),
            'actions' => $this->actionItems($competitor, $mine, $mineScores),
            'suggested_title' => $this->suggestedTitle($competitor, $mine),
        ];

        return $this->enhanceWithOpenAi($analysis);
    }

    private function fetchListing(string $url): array
    {
        $normalizedUrl = trim($url);
        if (!$this->isShopeeUrl($normalizedUrl)) {
            throw new ExternalServiceException('Informe links validos da Shopee para comparar os anuncios.', 422);
        }

        $playwrightListing = $this->fetchListingWithPlaywright($normalizedUrl);
        if (is_array($playwrightListing)) {
            return $playwrightListing;
        }

        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
            ])
            ->get($normalizedUrl);

        if (!$response->successful()) {
            throw new ExternalServiceException('Nao foi possivel carregar um dos links da Shopee para analise.', 422, [
                'url' => $normalizedUrl,
                'status' => $response->status(),
            ]);
        }

        return $this->extractListingData($normalizedUrl, (string) $response->body());
    }

    private function fetchListingWithPlaywright(string $url): ?array
    {
        $scriptPath = base_path('scripts/shopee-listing-scrape.mjs');
        $playwrightPackage = base_path('node_modules/playwright/package.json');

        if (!is_file($scriptPath) || !is_file($playwrightPackage)) {
            return null;
        }

        try {
            $result = Process::timeout(35)->run([
                'node',
                $scriptPath,
                $url,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (!$result->successful()) {
            return null;
        }

        $decoded = json_decode(trim($result->output()), true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'url' => $url,
            'host' => parse_url($url, PHP_URL_HOST) ?: '',
            'title' => $this->firstFilled([
                $decoded['title'] ?? null,
                $this->titleFromUrl($url),
            ]),
            'description' => $this->firstFilled([
                $decoded['description'] ?? null,
                $this->titleFromUrl($url),
            ]),
            'price' => $this->toFloat($decoded['price'] ?? null),
            'photo_count' => max(0, (int) ($decoded['photo_count'] ?? 0)),
            'images' => array_values(array_filter(array_map('strval', $decoded['images'] ?? []))),
            'has_video' => !empty($decoded['has_video']),
            'free_shipping' => !empty($decoded['free_shipping']),
            'sold_count' => $this->toFloat($decoded['sold_count'] ?? null),
            'rating' => $this->toFloat($decoded['rating'] ?? null),
            'response_time_text' => $this->firstFilled([
                $decoded['response_time_text'] ?? null,
            ]),
            'keywords' => $this->keywordsFor(
                trim(sprintf(
                    '%s %s %s',
                    (string) ($decoded['title'] ?? ''),
                    (string) ($decoded['description'] ?? ''),
                    implode(' ', array_map('strval', $decoded['tags'] ?? []))
                ))
            ),
            'capture_source' => 'playwright',
        ];
    }

    private function extractListingData(string $url, string $html): array
    {
        $meta = $this->extractMetaTags($html);
        $jsonLd = $this->extractJsonLd($html);
        $primaryJson = $jsonLd[0] ?? [];
        $urlTitle = $this->titleFromUrl($url);

        $title = $this->firstFilled([
            $meta['og:title'] ?? null,
            $meta['twitter:title'] ?? null,
            is_string($primaryJson['name'] ?? null) ? $primaryJson['name'] : null,
            $this->extractTitleTag($html),
            $urlTitle,
        ]);

        $description = $this->firstFilled([
            $meta['og:description'] ?? null,
            $meta['description'] ?? null,
            is_string($primaryJson['description'] ?? null) ? $primaryJson['description'] : null,
            $urlTitle,
        ]);

        $price = $this->firstNumeric([
            $meta['product:price:amount'] ?? null,
            data_get($primaryJson, 'offers.price'),
            $this->extractFirstMatch('/"price"\s*:\s*"?(\d+[.,]?\d*)"?/i', $html),
        ]);

        $images = $this->extractImages($jsonLd, $meta);
        $rating = $this->firstNumeric([
            data_get($primaryJson, 'aggregateRating.ratingValue'),
            $this->extractFirstMatch('/"ratingValue"\s*:\s*"?(\d+[.,]?\d*)"?/i', $html),
        ]);
        $soldCount = $this->firstNumeric([
            data_get($primaryJson, 'aggregateRating.ratingCount'),
            data_get($primaryJson, 'aggregateRating.reviewCount'),
            $this->extractFirstMatch('/(\d+[.,]?\d*)\s*(vendidos|vendas|sold)/iu', $html, 1),
        ]);
        $responseTimeText = $this->firstFilled([
            $this->extractFirstMatch('/responde\s+em\s+([^"<]+)/iu', $html),
            $this->extractFirstMatch('/tempo\s+de\s+resposta[^:]*:\s*([^"<]+)/iu', $html),
        ]);

        $hasVideo = preg_match('/video|video_url|mp4|\"video\"/iu', $html) === 1;
        $freeShipping = preg_match('/frete\s+gr[aá]tis|envio\s+gr[aá]tis|free\s+shipping/iu', $html) === 1;
        $keywords = $this->keywordsFor("{$title} {$description}");

        return [
            'url' => $url,
            'host' => parse_url($url, PHP_URL_HOST) ?: '',
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'photo_count' => count($images),
            'images' => $images,
            'has_video' => $hasVideo,
            'free_shipping' => $freeShipping,
            'sold_count' => $soldCount,
            'rating' => $rating,
            'response_time_text' => $responseTimeText,
            'keywords' => $keywords,
            'capture_source' => 'html',
        ];
    }

    private function scoresForListing(array $listing, array $competitor): array
    {
        $referenceKeywords = $competitor['keywords'] ?? [];
        $competitorPrice = (float) ($competitor['price'] ?? 0);

        $media = $this->scoreMedia($listing);
        $title = $this->scoreTitle((string) ($listing['title'] ?? ''), $referenceKeywords);
        $keywords = $this->scoreKeywords($listing['keywords'] ?? [], $referenceKeywords);
        $description = $this->scoreDescription((string) ($listing['description'] ?? ''));
        $offer = $this->scoreOffer($listing, $competitorPrice);
        $social = $this->socialScore($listing);
        $total = (int) round(($media + $title + $keywords + $description + $offer + $social) / 6);

        return compact('media', 'title', 'keywords', 'description', 'offer', 'social', 'total');
    }

    private function comparisonRows(array $competitor, array $mine, array $competitorScores, array $mineScores): array
    {
        return [
            [
                'id' => 'price',
                'label' => 'Preco',
                'competitor' => $this->moneyLabel((float) ($competitor['price'] ?? 0)),
                'mine' => $this->moneyLabel((float) ($mine['price'] ?? 0)),
                'winner' => $this->winnerByNumbers((float) ($competitor['price'] ?? 0), (float) ($mine['price'] ?? 0), true),
                'note' => (float) ($mine['price'] ?? 0) > (float) ($competitor['price'] ?? 0)
                    ? 'Seu preco esta acima do anuncio que lidera. Reveja margem, frete ou bonus da oferta.'
                    : 'Seu preco esta competitivo ou abaixo do topo.',
            ],
            [
                'id' => 'media',
                'label' => 'Fotos e video',
                'competitor' => sprintf('%d foto(s)%s', (int) ($competitor['photo_count'] ?? 0), !empty($competitor['has_video']) ? ' + video' : ''),
                'mine' => sprintf('%d foto(s)%s', (int) ($mine['photo_count'] ?? 0), !empty($mine['has_video']) ? ' + video' : ''),
                'winner' => $this->winnerByNumbers($competitorScores['media'], $mineScores['media']),
                'note' => $mineScores['media'] < $competitorScores['media']
                    ? 'Seu anuncio precisa de mais profundidade visual para segurar clique e conversao.'
                    : 'Sua estrutura visual esta no mesmo nivel ou acima.',
            ],
            [
                'id' => 'keywords',
                'label' => 'Palavras-chave',
                'competitor' => implode(', ', array_slice($competitor['keywords'] ?? [], 0, 6)) ?: 'Nao identificado',
                'mine' => implode(', ', array_slice($mine['keywords'] ?? [], 0, 6)) ?: 'Nao identificado',
                'winner' => $this->winnerByNumbers($competitorScores['keywords'], $mineScores['keywords']),
                'note' => $mineScores['keywords'] < $competitorScores['keywords']
                    ? 'Seu titulo e sua descricao nao estao cobrindo os mesmos termos fortes da busca.'
                    : 'Seu anuncio cobre bem as palavras-chave principais.',
            ],
            [
                'id' => 'description',
                'label' => 'Descricao',
                'competitor' => sprintf('%d caracteres', mb_strlen((string) ($competitor['description'] ?? ''))),
                'mine' => sprintf('%d caracteres', mb_strlen((string) ($mine['description'] ?? ''))),
                'winner' => $this->winnerByNumbers($competitorScores['description'], $mineScores['description']),
                'note' => $mineScores['description'] < $competitorScores['description']
                    ? 'Sua descricao esta curta ou fraca em beneficios, material, medida e garantia.'
                    : 'Sua descricao ja esta competitiva.',
            ],
            [
                'id' => 'social',
                'label' => 'Prova social',
                'competitor' => sprintf('Nota %.1f | %.0f vendas', (float) ($competitor['rating'] ?? 0), (float) ($competitor['sold_count'] ?? 0)),
                'mine' => sprintf('Nota %.1f | %.0f vendas', (float) ($mine['rating'] ?? 0), (float) ($mine['sold_count'] ?? 0)),
                'winner' => $this->winnerByNumbers($competitorScores['social'], $mineScores['social']),
                'note' => $mineScores['social'] < $competitorScores['social']
                    ? 'Seu anuncio esta perdendo em reputacao percebida e historico de vendas.'
                    : 'Seu anuncio nao esta atras na prova social.',
            ],
        ];
    }

    private function diagnosisBlocks(array $competitor, array $mine, array $competitorScores, array $mineScores): array
    {
        $missingKeywords = array_values(array_slice(array_diff($competitor['keywords'] ?? [], $mine['keywords'] ?? []), 0, 5));
        $competitorResponse = (string) ($competitor['response_time_text'] ?? '');
        $myResponse = (string) ($mine['response_time_text'] ?? '');

        return [
            [
                'id' => 'seo',
                'title' => 'SEO e palavras-chave',
                'tone' => $mineScores['keywords'] >= $competitorScores['keywords'] ? 'success' : 'critical',
                'result' => $missingKeywords === []
                    ? 'Seu anuncio cobre os termos principais da disputa.'
                    : 'Seu concorrente usa termos mais fortes na busca: ' . implode(', ', $missingKeywords) . '.',
                'suggestion' => $missingKeywords === []
                    ? 'Mantenha o titulo atual e teste apenas ordem das palavras.'
                    : 'Gere um novo titulo com os termos que faltam, mantendo leitura natural e clara.',
            ],
            [
                'id' => 'visual',
                'title' => 'Impacto visual',
                'tone' => $mineScores['media'] >= $competitorScores['media'] ? 'success' : 'warning',
                'result' => sprintf(
                    'O concorrente possui %d foto(s)%s. Voce possui %d foto(s)%s.',
                    (int) ($competitor['photo_count'] ?? 0),
                    !empty($competitor['has_video']) ? ' e 1 video' : '',
                    (int) ($mine['photo_count'] ?? 0),
                    !empty($mine['has_video']) ? ' e video' : ''
                ),
                'suggestion' => 'Adicione fotos com fundo branco, detalhes do produto, escala, embalagem e um video curto de demonstracao.',
            ],
            [
                'id' => 'description',
                'title' => 'Descricao e proposta de valor',
                'tone' => $mineScores['description'] >= $competitorScores['description'] ? 'success' : 'warning',
                'result' => $mineScores['description'] >= $competitorScores['description']
                    ? 'Sua descricao esta no mesmo nivel ou melhor estruturada.'
                    : 'A descricao dele responde melhor as duvidas sobre material, beneficios e seguranca de compra.',
                'suggestion' => 'Use blocos curtos, bullets, beneficios, material, medidas, prazo e garantia logo no inicio da descricao.',
            ],
            [
                'id' => 'social',
                'title' => 'Social proof',
                'tone' => $mineScores['social'] >= $competitorScores['social'] ? 'success' : 'warning',
                'result' => sprintf(
                    'Concorrente: nota %.1f e %.0f vendas. Voce: nota %.1f e %.0f vendas.%s',
                    (float) ($competitor['rating'] ?? 0),
                    (float) ($competitor['sold_count'] ?? 0),
                    (float) ($mine['rating'] ?? 0),
                    (float) ($mine['sold_count'] ?? 0),
                    $competitorResponse !== '' || $myResponse !== ''
                        ? sprintf(' Resposta no chat: concorrente %s / voce %s.', $competitorResponse ?: 'nao identificada', $myResponse ?: 'nao identificada')
                        : ' O tempo de resposta do chat nao foi encontrado na pagina publica.'
                ),
                'suggestion' => 'Fortaleca prova social com resposta mais rapida, oferta confiavel, mais historico de vendas e sinais claros de credibilidade.',
            ],
        ];
    }

    private function actionItems(array $competitor, array $mine, array $mineScores): array
    {
        $actions = [];
        $missingKeywords = array_values(array_slice(array_diff($competitor['keywords'] ?? [], $mine['keywords'] ?? []), 0, 6));
        $myPrice = (float) ($mine['price'] ?? 0);
        $competitorPrice = (float) ($competitor['price'] ?? 0);

        if ($mineScores['media'] < 60) {
            $actions[] = [
                'title' => 'Correcao urgente',
                'detail' => (int) ($mine['photo_count'] ?? 0) < 6
                    ? 'Seu anuncio esta com poucas fotos. Suba pelo menos 6 a 8 fotos e um video curto.'
                    : 'A primeira imagem e o video ainda estao fracos. Mostre produto, detalhe e uso real.',
                'tone' => 'critical',
            ];
        }

        if ($missingKeywords !== []) {
            $actions[] = [
                'title' => 'Oportunidade de melhoria',
                'detail' => 'Inclua no titulo e na descricao termos como: ' . implode(', ', $missingKeywords) . '.',
                'tone' => 'warning',
            ];
        }

        if ($mineScores['description'] < 55) {
            $actions[] = [
                'title' => 'Oportunidade de melhoria',
                'detail' => 'Reescreva a descricao com beneficios, material, medidas, prazo, garantia e bullets escaneaveis.',
                'tone' => 'warning',
            ];
        }

        if ($competitorPrice > 0 && $myPrice > ($competitorPrice * 1.06)) {
            $actions[] = [
                'title' => 'Correcao urgente',
                'detail' => 'Seu preco esta acima do lider. Se nao puder baixar, agregue kit, frete ou prova visual melhor.',
                'tone' => 'critical',
            ];
        }

        if ($actions === []) {
            $actions[] = [
                'title' => 'Ponto forte',
                'detail' => 'Seu anuncio ja esta competitivo. O proximo ganho deve vir de testes na primeira foto e no titulo.',
                'tone' => 'success',
            ];
        }

        return $actions;
    }

    private function radarRows(array $competitor, array $mine, array $competitorScores, array $mineScores): array
    {
        return [
            [
                'id' => 'price',
                'label' => 'Preco',
                'competitor' => $this->priceRadarScore((float) ($competitor['price'] ?? 0), (float) ($competitor['price'] ?? 0)),
                'mine' => $this->priceRadarScore((float) ($mine['price'] ?? 0), (float) ($competitor['price'] ?? 0)),
            ],
            [
                'id' => 'seo',
                'label' => 'SEO',
                'competitor' => (int) round(($competitorScores['title'] + $competitorScores['keywords']) / 2),
                'mine' => (int) round(($mineScores['title'] + $mineScores['keywords']) / 2),
            ],
            [
                'id' => 'photos',
                'label' => 'Fotos',
                'competitor' => $competitorScores['media'],
                'mine' => $mineScores['media'],
            ],
            [
                'id' => 'relevance',
                'label' => 'Relevancia',
                'competitor' => (int) round(($competitorScores['keywords'] + $competitorScores['description'] + $competitorScores['offer']) / 3),
                'mine' => (int) round(($mineScores['keywords'] + $mineScores['description'] + $mineScores['offer']) / 3),
            ],
            [
                'id' => 'service',
                'label' => 'Atendimento',
                'competitor' => $competitorScores['social'],
                'mine' => $mineScores['social'],
            ],
        ];
    }

    private function suggestedTitle(array $competitor, array $mine): string
    {
        $keywords = array_values(array_unique(array_merge(
            array_slice($competitor['keywords'] ?? [], 0, 5),
            array_slice($mine['keywords'] ?? [], 0, 3)
        )));

        return ucfirst(trim(implode(' ', array_slice($keywords, 0, 8))));
    }

    private function extractMetaTags(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $metaNodes = $xpath->query('//meta[@property or @name]');
        $meta = [];

        if ($metaNodes) {
            foreach ($metaNodes as $node) {
                $key = strtolower(trim((string) ($node->attributes?->getNamedItem('property')?->nodeValue ?? $node->attributes?->getNamedItem('name')?->nodeValue ?? '')));
                $value = trim((string) ($node->attributes?->getNamedItem('content')?->nodeValue ?? ''));

                if ($key !== '' && $value !== '') {
                    $meta[$key] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        return $meta;
    }

    private function extractJsonLd(string $html): array
    {
        preg_match_all('/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $matches);
        $rows = [];

        foreach ($matches[1] ?? [] as $chunk) {
            $decoded = json_decode(html_entity_decode(trim($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

            if (!is_array($decoded)) {
                continue;
            }

            if (array_is_list($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item)) {
                        $rows[] = $item;
                    }
                }

                continue;
            }

            $rows[] = $decoded;
        }

        return $rows;
    }

    private function extractTitleTag(string $html): ?string
    {
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return null;
        }

        return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractImages(array $jsonLd, array $meta): array
    {
        $images = [];

        foreach ($jsonLd as $item) {
            $candidate = $item['image'] ?? null;

            if (is_string($candidate) && $candidate !== '') {
                $images[] = $candidate;
                continue;
            }

            if (!is_array($candidate)) {
                continue;
            }

            foreach ($candidate as $value) {
                if (is_string($value) && $value !== '') {
                    $images[] = $value;
                }
            }
        }

        if ($images === [] && !empty($meta['og:image'])) {
            $images[] = (string) $meta['og:image'];
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function scoreMedia(array $listing): int
    {
        $photos = (int) ($listing['photo_count'] ?? 0);
        $score = 0;

        if ($photos >= 8) {
            $score += 70;
        } elseif ($photos >= 6) {
            $score += 55;
        } elseif ($photos >= 4) {
            $score += 38;
        } elseif ($photos >= 2) {
            $score += 22;
        }

        if (!empty($listing['has_video'])) {
            $score += 20;
        }

        if (!empty($listing['free_shipping'])) {
            $score += 10;
        }

        return $this->clamp($score);
    }

    private function scoreTitle(string $title, array $referenceKeywords): int
    {
        $titleKeywords = $this->keywordsFor($title);
        $length = mb_strlen($title);
        $score = 0;

        if ($length >= 65 && $length <= 120) {
            $score += 45;
        } elseif ($length >= 45 && $length <= 140) {
            $score += 30;
        } elseif ($length > 0) {
            $score += 15;
        }

        if ($referenceKeywords !== []) {
            $matched = count(array_intersect($referenceKeywords, $titleKeywords));
            $score += (int) round(($matched / max(1, count($referenceKeywords))) * 55);
        } elseif (count($titleKeywords) >= 5) {
            $score += 35;
        }

        return $this->clamp($score);
    }

    private function scoreKeywords(array $keywords, array $referenceKeywords): int
    {
        if ($keywords === []) {
            return 0;
        }

        if ($referenceKeywords === []) {
            return $this->clamp(35 + (count($keywords) * 6));
        }

        $matched = count(array_intersect($referenceKeywords, $keywords));

        return $this->clamp((int) round(($matched / max(1, count($referenceKeywords))) * 100));
    }

    private function scoreDescription(string $description): int
    {
        $length = mb_strlen(trim($description));
        $bulletCount = count(array_filter(
            preg_split('/\R/u', $description) ?: [],
            fn (string $line) => str_starts_with(trim($line), '-') || str_starts_with(trim($line), '•')
        ));
        $score = 0;

        if ($length >= 450) {
            $score += 50;
        } elseif ($length >= 250) {
            $score += 38;
        } elseif ($length >= 120) {
            $score += 24;
        } elseif ($length > 0) {
            $score += 12;
        }

        if ($bulletCount >= 4) {
            $score += 24;
        } elseif ($bulletCount >= 2) {
            $score += 14;
        }

        if (preg_match('/garantia|envio|qualidade|material|acabamento|medida/iu', $description) === 1) {
            $score += 16;
        }

        if (preg_match('/premium|personalizado|exclusivo|original/iu', $description) === 1) {
            $score += 10;
        }

        return $this->clamp($score);
    }

    private function scoreOffer(array $listing, float $competitorPrice): int
    {
        $price = (float) ($listing['price'] ?? 0);
        $soldCount = (float) ($listing['sold_count'] ?? 0);
        $rating = (float) ($listing['rating'] ?? 0);
        $score = 0;

        if ($price > 0 && $competitorPrice > 0) {
            $delta = (($price - $competitorPrice) / $competitorPrice) * 100;

            if ($delta <= 0) {
                $score += 35;
            } elseif ($delta <= 5) {
                $score += 24;
            } elseif ($delta <= 10) {
                $score += 14;
            }
        } elseif ($price > 0) {
            $score += 18;
        }

        if (!empty($listing['free_shipping'])) {
            $score += 20;
        }

        if ($rating >= 4.8) {
            $score += 25;
        } elseif ($rating >= 4.5) {
            $score += 18;
        } elseif ($rating >= 4.2) {
            $score += 10;
        }

        if ($soldCount >= 500) {
            $score += 20;
        } elseif ($soldCount >= 100) {
            $score += 14;
        } elseif ($soldCount >= 20) {
            $score += 8;
        }

        return $this->clamp($score);
    }

    private function socialScore(array $listing): int
    {
        $rating = (float) ($listing['rating'] ?? 0);
        $soldCount = (float) ($listing['sold_count'] ?? 0);
        $score = 0;

        if ($rating >= 4.9) {
            $score += 55;
        } elseif ($rating >= 4.7) {
            $score += 46;
        } elseif ($rating >= 4.5) {
            $score += 34;
        } elseif ($rating >= 4.0) {
            $score += 20;
        }

        if ($soldCount >= 500) {
            $score += 45;
        } elseif ($soldCount >= 100) {
            $score += 32;
        } elseif ($soldCount >= 20) {
            $score += 18;
        } elseif ($soldCount > 0) {
            $score += 10;
        }

        return $this->clamp($score);
    }

    private function priceRadarScore(float $price, float $competitorPrice): int
    {
        if ($price <= 0) {
            return 0;
        }

        if ($competitorPrice <= 0) {
            return 60;
        }

        $delta = (($price - $competitorPrice) / $competitorPrice) * 100;

        if ($delta <= 0) {
            return 100;
        }

        if ($delta <= 5) {
            return 78;
        }

        if ($delta <= 10) {
            return 55;
        }

        if ($delta <= 15) {
            return 32;
        }

        return 14;
    }

    private function keywordsFor(string $text): array
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($this->stripAccents($text))) ?: '';
        $words = array_filter(array_map('trim', explode(' ', $normalized)));
        $keywords = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }

            if (in_array($word, ['de', 'do', 'da', 'dos', 'das', 'para', 'com', 'sem', 'por', 'uma', 'um', 'nos', 'nas', 'que', 'pra'], true)) {
                continue;
            }

            $keywords[$word] = true;
        }

        return array_values(array_keys($keywords));
    }

    private function stripAccents(string $value): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }

    private function winnerByNumbers(float|int $competitor, float|int $mine, bool $lowerIsBetter = false): string
    {
        if ($competitor === $mine) {
            return 'tie';
        }

        if ($lowerIsBetter) {
            return $mine < $competitor ? 'mine' : 'competitor';
        }

        return $mine > $competitor ? 'mine' : 'competitor';
    }

    private function clamp(int|float $value, int $min = 0, int $max = 100): int
    {
        return max($min, min($max, (int) round($value)));
    }

    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function firstNumeric(array $values): float
    {
        foreach ($values as $value) {
            $number = $this->toFloat($value);
            if ($number > 0) {
                return $number;
            }
        }

        return 0;
    }

    private function toFloat(mixed $value): float
    {
        $normalized = trim(str_replace(',', '.', preg_replace('/[^\d,.-]+/', '', (string) $value) ?: ''));
        $number = (float) $normalized;

        return is_finite($number) ? $number : 0;
    }

    private function extractFirstMatch(string $pattern, string $subject, int $group = 1): ?string
    {
        if (!preg_match($pattern, $subject, $matches)) {
            return null;
        }

        return isset($matches[$group]) ? trim((string) $matches[$group]) : null;
    }

    private function moneyLabel(float $value): string
    {
        return $value > 0 ? 'R$ ' . number_format($value, 2, ',', '.') : 'Nao identificado';
    }

    private function isShopeeUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'shopee');
    }

    private function titleFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        $lastSegment = trim((string) last(array_filter(explode('/', $path))), '/');
        if ($lastSegment === '') {
            return '';
        }

        $decoded = urldecode($lastSegment);
        $decoded = preg_replace('/-i\.\d+\.\d+.*$/i', '', $decoded) ?? $decoded;
        $decoded = preg_replace('/[_-]+/', ' ', $decoded) ?? $decoded;
        $decoded = preg_replace('/\s+/', ' ', $decoded) ?? $decoded;
        $decoded = trim($decoded);

        return mb_convert_case($decoded, MB_CASE_TITLE, 'UTF-8');
    }

    private function enhanceWithOpenAi(array $analysis): array
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            return $analysis;
        }

        try {
            $payload = [
                'model' => (string) config('services.openai.model', 'gpt-5-mini'),
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => 'Voce e um analista especialista em Shopee. Compare o anuncio concorrente e o meu anuncio. Responda apenas com JSON estruturado. Priorize diagnostico pratico e correcao objetiva. Nao invente dados ausentes.',
                            ],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => json_encode([
                                    'goal' => 'Comparar dois anuncios da Shopee e devolver um plano de melhorias claro para UX visual.',
                                    'data' => $analysis,
                                    'instructions' => [
                                        'Preencha diagnosis com 4 blocos: seo, visual, description, social.',
                                        'Cada bloco deve ter title, tone, result e suggestion.',
                                        'Preencha actions com 3 a 6 cards curtos.',
                                        'Gere suggested_title melhor que o atual se houver dados suficientes.',
                                        'Se algum dado estiver ausente, explique a limitacao sem inventar.',
                                    ],
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                ],
                'max_output_tokens' => 2200,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'shopee_ad_analysis',
                        'strict' => true,
                        'schema' => $this->openAiSchema(),
                    ],
                ],
            ];

            $response = Http::baseUrl((string) config('services.openai.base_url', 'https://api.openai.com/v1'))
                ->timeout(40)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('/responses', $payload);

            if (!$response->successful()) {
                return $analysis;
            }

            $parsed = $this->extractOpenAiJson($response->json());
            if (!is_array($parsed)) {
                return $analysis;
            }

            if (isset($parsed['diagnosis']) && is_array($parsed['diagnosis'])) {
                $analysis['diagnosis'] = $parsed['diagnosis'];
            }

            if (isset($parsed['actions']) && is_array($parsed['actions'])) {
                $analysis['actions'] = $parsed['actions'];
            }

            if (!empty($parsed['suggested_title']) && is_string($parsed['suggested_title'])) {
                $analysis['suggested_title'] = trim($parsed['suggested_title']);
            }

            if (isset($parsed['summary_note']) && is_string($parsed['summary_note'])) {
                $analysis['summary']['ai_note'] = trim($parsed['summary_note']);
            }

            return $analysis;
        } catch (Throwable) {
            return $analysis;
        }
    }

    private function extractOpenAiJson(array $payload): ?array
    {
        $candidates = [
            data_get($payload, 'output_text'),
            data_get($payload, 'output.0.content.0.text'),
            data_get($payload, 'output.1.content.0.text'),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function openAiSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary_note' => [
                    'type' => 'string',
                ],
                'suggested_title' => [
                    'type' => 'string',
                ],
                'diagnosis' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'tone' => [
                                'type' => 'string',
                                'enum' => ['critical', 'warning', 'success'],
                            ],
                            'result' => ['type' => 'string'],
                            'suggestion' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'title', 'tone', 'result', 'suggestion'],
                    ],
                ],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'tone' => [
                                'type' => 'string',
                                'enum' => ['critical', 'warning', 'success'],
                            ],
                        ],
                        'required' => ['title', 'detail', 'tone'],
                    ],
                ],
            ],
            'required' => ['summary_note', 'suggested_title', 'diagnosis', 'actions'],
        ];
    }
}
