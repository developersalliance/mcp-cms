<?php

class AIClientException extends Exception {}

class AIClient
{
    private string $provider;
    private string $apiKey;
    private string $model;

    public function __construct(string $provider, string $apiKey, string $model)
    {
        $this->provider = $provider;
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public static function fromConfig(array $config): ?AIClient
    {
        $provider = $config['ai_provider'] ?? '';
        $apiKey = $config['ai_api_key'] ?? '';
        $model = $config['ai_model'] ?? '';
        if ($provider === '' || $apiKey === '' || $model === '') {
            return null;
        }
        return new self($provider, $apiKey, $model);
    }

    public function complete(string $prompt, string $systemPrompt = '', bool $wantJson = false, int $maxTokens = 4096): string
    {
        return match ($this->provider) {
            'anthropic' => $this->callAnthropic($prompt, $systemPrompt, $wantJson, $maxTokens),
            'openai'    => $this->callOpenAI($prompt, $systemPrompt, $wantJson, $maxTokens),
            'gemini'    => $this->callGemini($prompt, $systemPrompt, $wantJson, $maxTokens),
            default     => throw new AIClientException('Unknown AI provider: ' . $this->provider),
        };
    }

    public function testConnection(): array
    {
        try {
            $reply = $this->complete('Reply with the single word: OK', '', false, 16);
            return ['success' => true, 'reply' => trim($reply)];
        } catch (AIClientException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function callAnthropic(string $prompt, string $systemPrompt, bool $wantJson, int $maxTokens): string
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($systemPrompt !== '' || $wantJson) {
            $system = $systemPrompt;
            if ($wantJson) {
                $system = trim($system . "\n\nRespond with valid JSON only. No prose, no markdown fences.");
            }
            $body['system'] = $system;
        }
        $response = $this->httpPost('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ], json_encode($body));
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new AIClientException('Anthropic: invalid JSON response');
        }
        if (isset($data['error'])) {
            throw new AIClientException('Anthropic: ' . ($data['error']['message'] ?? 'unknown error'));
        }
        $text = $data['content'][0]['text'] ?? null;
        if ($text === null) {
            throw new AIClientException('Anthropic: missing content in response');
        }
        return $wantJson ? $this->stripJsonFences($text) : $text;
    }

    private function callOpenAI(string $prompt, string $systemPrompt, bool $wantJson, int $maxTokens): string
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_completion_tokens' => $maxTokens,
        ];
        if ($wantJson) {
            $body['response_format'] = ['type' => 'json_object'];
            if ($systemPrompt === '') {
                array_unshift($body['messages'], ['role' => 'system', 'content' => 'Respond with valid JSON only.']);
            }
        }
        $response = $this->httpPost('https://api.openai.com/v1/chat/completions', [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ], json_encode($body));
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new AIClientException('OpenAI: invalid JSON response');
        }
        if (isset($data['error'])) {
            throw new AIClientException('OpenAI: ' . ($data['error']['message'] ?? 'unknown error'));
        }
        $text = $data['choices'][0]['message']['content'] ?? null;
        if ($text === null) {
            throw new AIClientException('OpenAI: missing content in response');
        }
        return $text;
    }

    private function callGemini(string $prompt, string $systemPrompt, bool $wantJson, int $maxTokens): string
    {
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ];
        if ($systemPrompt !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }
        if ($wantJson) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($this->apiKey);
        $response = $this->httpPost($url, ['Content-Type: application/json'], json_encode($body));
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new AIClientException('Gemini: invalid JSON response');
        }
        if (isset($data['error'])) {
            throw new AIClientException('Gemini: ' . ($data['error']['message'] ?? 'unknown error'));
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text === null) {
            throw new AIClientException('Gemini: missing content in response');
        }
        return $text;
    }

    private function httpPost(string $url, array $headers, string $body): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new AIClientException('HTTP request failed: ' . $err);
            }
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status < 200 || $status >= 300) {
                throw new AIClientException('HTTP ' . $status . ': ' . substr($response, 0, 500));
            }
            return $response;
        }

        if (!ini_get('allow_url_fopen')) {
            throw new AIClientException('Neither curl nor allow_url_fopen is available');
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            throw new AIClientException('HTTP request failed');
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if ($status < 200 || $status >= 300) {
            throw new AIClientException('HTTP ' . $status . ': ' . substr($response, 0, 500));
        }
        return $response;
    }

    private function stripJsonFences(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```\s*$/s', $text, $m)) {
            return trim($m[1]);
        }
        return $text;
    }
}
