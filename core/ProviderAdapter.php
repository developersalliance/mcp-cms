<?php
/**
 * ProviderAdapter — translate tool-using chat between providers.
 *
 * The CMS keeps its conversation in Anthropic shape internally (that's
 * the format edit-ai-chat.php has used since day one). This adapter
 * translates to/from OpenAI and Gemini on the wire so the same
 * controller works against any of the three providers.
 *
 * Internal message shape (Anthropic-style):
 *   [
 *     ['role' => 'user',      'content' => 'plain string' | [{type, ...}, ...]],
 *     ['role' => 'assistant', 'content' => [{type:'text', text}, {type:'tool_use', id, name, input}]],
 *     ['role' => 'user',      'content' => [{type:'tool_result', tool_use_id, content}, ...]],
 *   ]
 *
 * Internal tool shape (Anthropic-style):
 *   [['name', 'description', 'input_schema'], ...]
 *
 * Normalized response (what callWithTools returns):
 *   ['content' => [...Anthropic-style blocks...], 'stop_reason' => 'tool_use' | 'end_turn', '_error' => '...']
 */
class ProviderAdapter
{
    public static function callWithTools(
        string $provider,
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $messages,
        array $tools,
        int $maxTokens = 4096
    ): array {
        switch ($provider) {
            case 'anthropic':
                return self::callAnthropic($apiKey, $model, $systemPrompt, $messages, $tools, $maxTokens);
            case 'openai':
                return self::callOpenAI($apiKey, $model, $systemPrompt, $messages, $tools, $maxTokens);
            case 'gemini':
                return self::callGemini($apiKey, $model, $systemPrompt, $messages, $tools, $maxTokens);
            default:
                return ['_error' => 'Unknown provider: ' . $provider];
        }
    }

    // ---------- Anthropic ----------

    private static function callAnthropic(string $apiKey, string $model, string $system, array $messages, array $tools, int $maxTokens): array
    {
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
            'tools' => $tools,
        ];
        $resp = self::http('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ], json_encode($body));
        if (isset($resp['_error'])) return $resp;
        $j = $resp['body'];
        if ($resp['code'] >= 400) {
            return ['_error' => 'Anthropic ' . $resp['code'] . ': ' . ($j['error']['message'] ?? $resp['raw'])];
        }
        if (!isset($j['content'])) return ['_error' => 'Anthropic: unexpected response shape'];
        return ['content' => $j['content'], 'stop_reason' => $j['stop_reason'] ?? 'end_turn'];
    }

    // ---------- OpenAI ----------

    private static function callOpenAI(string $apiKey, string $model, string $system, array $messages, array $tools, int $maxTokens): array
    {
        // System prompt becomes the first message in OpenAI.
        $oaMessages = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            // Plain string content (single text block)
            if (is_string($content)) {
                $oaMessages[] = ['role' => $role, 'content' => $content];
                continue;
            }
            if (!is_array($content)) continue;

            if ($role === 'assistant') {
                // Anthropic mixes text + tool_use blocks in assistant content.
                // OpenAI puts text in `content` and tools in `tool_calls`.
                $text = '';
                $toolCalls = [];
                foreach ($content as $b) {
                    $t = $b['type'] ?? '';
                    if ($t === 'text') {
                        $text .= $b['text'] ?? '';
                    } elseif ($t === 'tool_use') {
                        $toolCalls[] = [
                            'id' => (string)($b['id'] ?? ''),
                            'type' => 'function',
                            'function' => [
                                'name' => $b['name'],
                                'arguments' => json_encode(is_array($b['input']) || is_object($b['input']) ? $b['input'] : []),
                            ],
                        ];
                    }
                }
                $msg = ['role' => 'assistant'];
                if ($text !== '') $msg['content'] = $text;
                if (!empty($toolCalls)) $msg['tool_calls'] = $toolCalls;
                if ($text === '' && empty($toolCalls)) $msg['content'] = '';
                $oaMessages[] = $msg;
                continue;
            }

            if ($role === 'user') {
                // Two cases: array of tool_results (one or more) → emit one
                // OpenAI message per tool_result with role=tool.
                // Or array of text+image blocks → flatten text to content
                // (images dropped — minimal port).
                $toolResults = [];
                $text = '';
                foreach ($content as $b) {
                    $t = $b['type'] ?? '';
                    if ($t === 'tool_result') {
                        $toolResults[] = [
                            'role' => 'tool',
                            'tool_call_id' => (string)($b['tool_use_id'] ?? ''),
                            'content' => is_string($b['content'] ?? '') ? $b['content'] : json_encode($b['content']),
                        ];
                    } elseif ($t === 'text') {
                        $text .= $b['text'] ?? '';
                    }
                }
                foreach ($toolResults as $tr) $oaMessages[] = $tr;
                if ($text !== '') $oaMessages[] = ['role' => 'user', 'content' => $text];
                continue;
            }
        }

        // Translate tools (Anthropic input_schema → OpenAI function parameters)
        $oaTools = [];
        foreach ($tools as $t) {
            $oaTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $t['name'],
                    'description' => $t['description'] ?? $t['name'],
                    'parameters' => $t['input_schema'] ?? ['type' => 'object', 'properties' => new stdClass()],
                ],
            ];
        }

        $body = [
            'model' => $model,
            'messages' => $oaMessages,
            'max_tokens' => $maxTokens,
        ];
        if (!empty($oaTools)) {
            $body['tools'] = $oaTools;
            $body['tool_choice'] = 'auto';
        }
        $resp = self::http('https://api.openai.com/v1/chat/completions', [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ], json_encode($body));
        if (isset($resp['_error'])) return $resp;
        $j = $resp['body'];
        if ($resp['code'] >= 400) {
            return ['_error' => 'OpenAI ' . $resp['code'] . ': ' . ($j['error']['message'] ?? $resp['raw'])];
        }
        $choice = $j['choices'][0] ?? null;
        if (!$choice) return ['_error' => 'OpenAI: missing choice'];

        $msg = $choice['message'] ?? [];
        $blocks = [];
        $textC = $msg['content'] ?? null;
        if (is_string($textC) && $textC !== '') {
            $blocks[] = ['type' => 'text', 'text' => $textC];
        }
        foreach (($msg['tool_calls'] ?? []) as $tc) {
            $args = $tc['function']['arguments'] ?? '{}';
            $parsed = is_string($args) ? json_decode($args, true) : $args;
            if (!is_array($parsed)) $parsed = [];
            $blocks[] = [
                'type' => 'tool_use',
                'id' => (string)($tc['id'] ?? 'call_' . substr(md5(uniqid('', true)), 0, 8)),
                'name' => $tc['function']['name'] ?? '',
                'input' => empty($parsed) ? new stdClass() : $parsed,
            ];
        }
        $finish = $choice['finish_reason'] ?? 'stop';
        $stopReason = ($finish === 'tool_calls') ? 'tool_use' : 'end_turn';
        return ['content' => $blocks, 'stop_reason' => $stopReason];
    }

    // ---------- Gemini ----------

    private static function callGemini(string $apiKey, string $model, string $system, array $messages, array $tools, int $maxTokens): array
    {
        // Walk our internal messages and produce Gemini `contents`.
        // Gemini doesn't carry tool-call IDs; we track name lookup by
        // order so we can map our tool_results back to function names.
        $contents = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            $gRole = ($role === 'assistant') ? 'model' : 'user';

            $parts = [];
            if (is_string($content)) {
                if ($content !== '') $parts[] = ['text' => $content];
            } elseif (is_array($content)) {
                foreach ($content as $b) {
                    $t = $b['type'] ?? '';
                    if ($t === 'text') {
                        $parts[] = ['text' => (string)($b['text'] ?? '')];
                    } elseif ($t === 'tool_use') {
                        $args = $b['input'] ?? new stdClass();
                        if (is_array($args) && empty($args)) $args = new stdClass();
                        $parts[] = ['functionCall' => ['name' => $b['name'], 'args' => $args]];
                    } elseif ($t === 'tool_result') {
                        // Look up function name from the preceding model turn
                        // by id; if not found, send the id as the name as a
                        // fallback (Gemini will pair by name).
                        $toolName = self::lookupToolNameById($messages, $b['tool_use_id'] ?? '');
                        $responseBody = is_string($b['content'] ?? '') ? ['content' => $b['content']] : (array)($b['content'] ?? []);
                        $parts[] = ['functionResponse' => ['name' => $toolName ?: 'unknown', 'response' => $responseBody]];
                    }
                }
            }
            if (empty($parts)) continue;
            $contents[] = ['role' => $gRole, 'parts' => $parts];
        }

        // Translate tools
        $functionDeclarations = [];
        foreach ($tools as $t) {
            $functionDeclarations[] = [
                'name' => $t['name'],
                'description' => $t['description'] ?? $t['name'],
                'parameters' => self::sanitizeJsonSchemaForGemini($t['input_schema'] ?? ['type' => 'object', 'properties' => new stdClass()]),
            ];
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ];
        if ($system !== '') $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        if (!empty($functionDeclarations)) {
            $body['tools'] = [['functionDeclarations' => $functionDeclarations]];
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $resp = self::http($url, ['Content-Type: application/json'], json_encode($body));
        if (isset($resp['_error'])) return $resp;
        $j = $resp['body'];
        if ($resp['code'] >= 400) {
            return ['_error' => 'Gemini ' . $resp['code'] . ': ' . ($j['error']['message'] ?? $resp['raw'])];
        }
        $parts = $j['candidates'][0]['content']['parts'] ?? [];
        $blocks = [];
        $hasToolCall = false;
        $callIdx = 0;
        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $blocks[] = ['type' => 'text', 'text' => (string)$p['text']];
            } elseif (isset($p['functionCall'])) {
                $fc = $p['functionCall'];
                $name = (string)($fc['name'] ?? '');
                $args = $fc['args'] ?? new stdClass();
                if (is_array($args) && empty($args)) $args = new stdClass();
                // Gemini doesn't give us ids — synthesize one so the tool
                // loop downstream can match tool_result back to tool_use.
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => 'gem_' . substr(md5($name . '_' . ($callIdx++) . '_' . microtime(true)), 0, 12),
                    'name' => $name,
                    'input' => $args,
                ];
                $hasToolCall = true;
            }
        }
        return ['content' => $blocks, 'stop_reason' => $hasToolCall ? 'tool_use' : 'end_turn'];
    }

    /**
     * Gemini's parameters schema is JSON Schema, but with a few omissions —
     * notably it doesn't accept `additionalProperties` or `default`. Strip
     * those to be safe. Also: `type: object` with no properties should be
     * an empty object rather than the `new stdClass()` PHP-roundtrip issue.
     */
    private static function sanitizeJsonSchemaForGemini($schema)
    {
        if (!is_array($schema)) return $schema;
        unset($schema['additionalProperties'], $schema['$schema'], $schema['$id']);
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $k => $v) {
                $schema['properties'][$k] = self::sanitizeJsonSchemaForGemini($v);
            }
            if (empty($schema['properties'])) {
                $schema['properties'] = new stdClass();
            }
        }
        if (isset($schema['items'])) {
            $schema['items'] = self::sanitizeJsonSchemaForGemini($schema['items']);
        }
        return $schema;
    }

    /**
     * Walk the conversation in reverse to find the tool_use whose id matches
     * $id and return its name. Used when translating tool_result → Gemini's
     * functionResponse, which needs the name (Gemini has no ids).
     */
    private static function lookupToolNameById(array $messages, string $id): ?string
    {
        if ($id === '') return null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $c = $messages[$i]['content'] ?? null;
            if (!is_array($c)) continue;
            foreach ($c as $b) {
                if (($b['type'] ?? '') === 'tool_use' && ($b['id'] ?? '') === $id) {
                    return $b['name'] ?? null;
                }
            }
        }
        return null;
    }

    // ---------- shared HTTP ----------

    private static function http(string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            return ['_error' => 'curl extension not available'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 90,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['_error' => 'curl: ' . $err];
        }
        $j = json_decode($resp, true);
        return ['code' => $code, 'body' => is_array($j) ? $j : [], 'raw' => $resp];
    }
}
