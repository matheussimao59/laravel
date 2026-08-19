<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class GpAiController
{
    public function generateProduct(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Nome do produto e obrigatorio.', 'errors' => $validator->errors()], 422);
        }

        $apiKey = config('services.openai.api_key');
        $baseUrl = config('services.openai.base_url');
        $model = config('services.openai.model', 'gpt-5-mini');

        if (!$apiKey) {
            return response()->json(['message' => 'Chave de API da IA nao configurada no servidor.'], 500);
        }

        $productName = trim($request->input('name'));

        $prompt = <<<EOT
Voce e um assistente de uma grafica rapida e personalizada chamada "Unica Print".
O usuario quer criar um produto chamado "{$productName}".

Retorne APENAS um JSON valido (sem markdown, sem ```json) com os campos:
{
  "category_name": "Nome da categoria mais adequada para este produto (ex: Cartoes de Visita, Adesivos, Camisetas, Banners, Canecas, etc)",
  "description": "Descricao curta e objetiva do produto para uma grafica (2-3 frases)",
  "sell_price": numero sugerido de preco de venda em BRL (ex: 45.00),
  "pricing_type": "fixed" ou "per_sheet" — escolha o mais adequado para o tipo de produto,
  "image_prompt": "Prompt curto em ingles para gerar uma imagem profissional do produto, fundo branco, estilo catalogo"
}

Regras:
- O preco deve ser realista para uma grafica brasileira
- "fixed" = preco por unidade/pedido; "per_sheet" = preco por folha de impressao
- A descricao deve mencionar materiais comuns de grafica
- Nao retorne nada alem do JSON
EOT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->post($baseUrl . '/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 500,
            ]);

            if (!$response->successful()) {
                $error = $response->json('error.message') ?? 'Erro na API da IA.';
                return response()->json(['message' => 'Erro ao comunicar com a IA: ' . $error], 502);
            }

            $content = $response->json('choices.0.message.content', '');
            $content = trim($content);
            $content = preg_replace('/^```json\s*/i', '', $content);
            $content = preg_replace('/\s*```$/i', '', $content);
            $content = trim($content);

            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['message' => 'A IA retornou dados invalidos. Tente novamente.'], 502);
            }

            return response()->json([
                'message' => 'Produto gerado com sucesso pela IA.',
                'data' => [
                    'category_name' => $data['category_name'] ?? $productName,
                    'description' => $data['description'] ?? '',
                    'sell_price' => (float) ($data['sell_price'] ?? 0),
                    'pricing_type' => $data['pricing_type'] === 'per_sheet' ? 'per_sheet' : 'fixed',
                    'image_prompt' => $data['image_prompt'] ?? '',
                ],
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(['message' => 'Servidor da IA sem resposta. Verifique a conexao.'], 504);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro inesperado ao gerar produto: ' . $e->getMessage()], 500);
        }
    }
}
