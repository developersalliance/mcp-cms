<?php
/**
 * ImageGenerator — produce an image from a text prompt with the configured
 * AI provider and hand the bytes to UploadManager.
 *
 *   openai    → POST /v1/images/generations (gpt-image-1, b64_json)
 *   gemini    → generateContent with responseModalities IMAGE (inline base64)
 *   anthropic → not supported (clear error)
 *
 * The HTTP call is injectable ($httpPost) so tests never hit a provider.
 */
class ImageGenerator
{
    private string $provider;
    private string $apiKey;
    /** @var callable(string $url, array $headers, array $body): array{status:int, body:string} */
    private $httpPost;

    public const SIZES = ['1024x1024', '1536x1024', '1024x1536', '1792x1024', '1024x1792', '512x512'];

    public function __construct(string $provider, string $apiKey, ?callable $httpPost = null)
    {
        $this->provider = strtolower(trim($provider));
        $this->apiKey = trim($apiKey);
        $this->httpPost = $httpPost ?: [$this, 'defaultHttpPost'];
    }

    public static function fromConfig(array $config, ?callable $httpPost = null): self
    {
        return new self((string)($config['ai_provider'] ?? ''), (string)($config['ai_api_key'] ?? ''), $httpPost);
    }

    /**
     * @return array{success:bool, bytes?:string, mime?:string, error?:string, model?:string}
     */
    public function generate(string $prompt, string $size = '1024x1024'): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'error' => 'A prompt is required'];
        }
        if (!in_array($size, self::SIZES, true)) {
            return ['success' => false, 'error' => 'Unsupported size. Use one of: ' . implode(', ', self::SIZES)];
        }
        if ($this->provider === '' || $this->apiKey === '') {
            return ['success' => false, 'error' => 'No AI provider configured. Set the provider and API key in Settings → AI.'];
        }
        try {
            switch ($this->provider) {
                case 'openai':
                    return $this->viaOpenAI($prompt, $size);
                case 'gemini':
                case 'google':
                    return $this->viaGemini($prompt, $size);
                case 'anthropic':
                case 'claude':
                    return ['success' => false, 'error' => 'The configured AI provider (Anthropic) cannot generate images; switch the AI provider to OpenAI or Gemini in Settings.'];
                default:
                    return ['success' => false, 'error' => 'Unknown AI provider: ' . $this->provider];
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Image generation failed: ' . $e->getMessage()];
        }
    }

    private function viaOpenAI(string $prompt, string $size): array
    {
        // gpt-image-1 accepts 1024x1024, 1536x1024, 1024x1536 (and "auto").
        $map = ['1792x1024' => '1536x1024', '1024x1792' => '1024x1536', '512x512' => '1024x1024'];
        $size = $map[$size] ?? $size;
        $resp = ($this->httpPost)('https://api.openai.com/v1/images/generations', [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ], ['model' => 'gpt-image-1', 'prompt' => $prompt, 'n' => 1, 'size' => $size, 'output_format' => 'png']);
        $data = json_decode((string)($resp['body'] ?? ''), true);
        if (($resp['status'] ?? 0) !== 200) {
            $msg = $data['error']['message'] ?? ('HTTP ' . ($resp['status'] ?? '?'));
            throw new Exception('OpenAI: ' . $msg);
        }
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            throw new Exception('OpenAI returned no image data');
        }
        return $this->decode($b64, 'image/png', 'gpt-image-1');
    }

    private function viaGemini(string $prompt, string $size): array
    {
        $model = 'gemini-2.5-flash-image';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);
        $resp = ($this->httpPost)($url, ['Content-Type: application/json'], [
            'contents' => [['parts' => [['text' => $prompt . ' (target aspect ' . str_replace('x', ':', $size) . ')']]]],
            'generationConfig' => ['responseModalities' => ['IMAGE', 'TEXT']],
        ]);
        $data = json_decode((string)($resp['body'] ?? ''), true);
        if (($resp['status'] ?? 0) !== 200) {
            $msg = $data['error']['message'] ?? ('HTTP ' . ($resp['status'] ?? '?'));
            throw new Exception('Gemini: ' . $msg);
        }
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (is_array($inline) && !empty($inline['data'])) {
                $mime = (string)($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png');
                return $this->decode((string)$inline['data'], $mime, $model);
            }
        }
        throw new Exception('Gemini returned no image data');
    }

    private function decode(string $b64, string $mime, string $model): array
    {
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new Exception('Provider returned invalid base64 image data');
        }
        return ['success' => true, 'bytes' => $bytes, 'mime' => $mime, 'model' => $model];
    }

    private function defaultHttpPost(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('network error: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$out];
    }
}
