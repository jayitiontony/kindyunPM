<?php
/**
 * AI 助手主调用逻辑
 * AI Assistant Core
 *
 * - callLlm(): 调本地 OpenAI 兼容 chat/completions
 * - runAssistantLoop(): 多轮 tool call 循环,直到 LLM 给最终答复
 *
 * 支持任意 OpenAI 兼容的本地/远端服务:
 *   - Ollama (/v1/chat/completions)
 *   - vLLM
 *   - LM Studio
 *   - Xinference
 *   - OpenAI 官方
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_tools.php';

/**
 * 默认 system prompt(用户可自定义覆盖)
 */
function getDefaultSystemPrompt($user) {
    $name = $user['name'] ?: $user['username'];
    $role = $user['role_name'] ?? '';
    $expertise = $user['expertise'] ?? '';
    return <<<EOT
你是 Kindyun PM 系统的 AI 项目管理助手。当前用户: $name ($role), 专长: $expertise。

你可以调用一组项目管理工具(functions)来帮用户完成工作。所有操作都自动限定在当前用户权限范围内。

核心能力:
1. **任务规划与分解**: 用户给目标,帮分解成多个子任务并创建
2. **任务发布**: 在指定项目下创建任务,指派给具体负责人(必须填原因)
3. **状态更新**: 推进任务进度(待处理→进行中→已完成)
4. **查询汇报**: 拉取本周/指定范围的任务汇总,生成汇报文本
5. **协助沟通**: 在任务下加评论,标记阻塞并请求协助

工作原则:
- 工具调用尽量精准,不要乱调用
- 创建任务时如果用户没说指派给谁,先创建(不指派),然后问用户
- 指派时**必须**填指派原因(assign_reason)
- 创建项目时默认把创建者作为项目经理
- 涉及多步操作时,先列计划再执行
- 用中文回答,简洁、结构化,善用 markdown 列表
EOT;
}

/**
 * 调 LLM(单次,带 tool support)
 * @return array ['ok'=>bool, 'data'=>array(LLM response), 'error'=>string, 'http_code'=>int]
 */
function callLlm($settings, $messages, $tools = null) {
    $apiBase = rtrim($settings['api_base'] ?? 'http://localhost:11434/v1', '/');
    $apiKey  = $settings['api_key'] ?? '';
    $model   = $settings['model'] ?? 'gpt-3.5-turbo';
    $temp    = isset($settings['temperature']) ? (float)$settings['temperature'] : 0.7;
    $maxTok  = isset($settings['max_tokens']) ? (int)$settings['max_tokens'] : 2000;

    $url = $apiBase . '/chat/completions';
    $body = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temp,
        'max_tokens'  => $maxTok,
        'stream'      => false,
    ];
    if (!empty($tools)) {
        $body['tools'] = $tools;
        $body['tool_choice'] = 'auto';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 180,   // 3 分钟(AI 长对话用)
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => '网络错误: ' . $err, 'http_code' => 0, 'data' => null];
    }
    $data = json_decode($resp, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? $resp;
        return ['ok' => false, 'error' => 'LLM 返回 HTTP ' . $code . ': ' . $msg, 'http_code' => $code, 'data' => $data];
    }
    if (!is_array($data) || !isset($data['choices'][0])) {
        return ['ok' => false, 'error' => 'LLM 返回格式异常: ' . substr($resp, 0, 200), 'http_code' => $code, 'data' => $data];
    }
    return ['ok' => true, 'data' => $data, 'error' => null, 'http_code' => $code];
}

/**
 * 流式调 LLM(OpenAI-compatible SSE)
 * 边读 chunk 边回调,流式返回 content delta
 *
 * @param array    $settings
 * @param array    $messages
 * @param array    $tools
 * @param callable $onDelta    function(string $contentDelta, array $fullMessage, bool $isFinal): void
 *                            - contentDelta: 每次新到的 content 增量(可能为空)
 *                            - fullMessage:   累积的 message (含 tool_calls)
 *                            - isFinal:      是否是最后一个 chunk
 * @return array ['ok'=>bool, 'data'=>array(完整 message), 'error'=>string]
 *
 * 协议: OpenAI chat/completion stream 格式,每行 "data: {...}\n\n", 末尾 "data: [DONE]\n\n"
 */
function callLlmStream($settings, $messages, $tools, $onDelta) {
    $apiBase = rtrim($settings['api_base'] ?? 'http://localhost:11434/v1', '/');
    $apiKey  = $settings['api_key'] ?? '';
    $model   = $settings['model'] ?? 'gpt-3.5-turbo';
    $temp    = isset($settings['temperature']) ? (float)$settings['temperature'] : 0.7;
    $maxTok  = isset($settings['max_tokens']) ? (int)$settings['max_tokens'] : 2000;

    $url = $apiBase . '/chat/completions';
    $body = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temp,
        'max_tokens'  => $maxTok,
        'stream'      => true,
    ];
    if (!empty($tools)) {
        $body['tools'] = $tools;
        $body['tool_choice'] = 'auto';
    }

    // 关掉输出缓冲,确保 echo/flush 立即到客户端
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) { ob_end_flush(); }
    @ob_implicit_flush(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Accept: text/event-stream',
        ],
        CURLOPT_TIMEOUT        => 180,        // 流式 3 分钟上限
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_BUFFERSIZE     => 256,        // 小缓冲,频繁回调
        CURLOPT_WRITEFUNCTION  => function($ch, $chunk) use ($onDelta, &$accumulated, &$toolCallsAccum, &$finishReason) {
            static $sseBuffer = '';
            $sseBuffer .= $chunk;

            // SSE 用 \n\n 分事件
            while (($pos = strpos($sseBuffer, "\n\n")) !== false) {
                $event = substr($sseBuffer, 0, $pos);
                $sseBuffer = substr($sseBuffer, $pos + 2);
                $event = trim($event);
                if ($event === '' || strpos($event, 'data:') !== 0) continue;
                $payload = trim(substr($event, 5));
                if ($payload === '[DONE]') {
                    if (is_callable($onDelta)) {
                        $onDelta('', $accumulated, true);
                    }
                    return strlen($chunk);
                }
                $j = json_decode($payload, true);
                if (!is_array($j) || !isset($j['choices'][0])) continue;
                $choice = $j['choices'][0];
                $delta = $choice['delta'] ?? [];
                if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                    $finishReason = $choice['finish_reason'];
                }
                if (!isset($accumulated)) {
                    $accumulated = ['role' => $delta['role'] ?? 'assistant', 'content' => ''];
                    $toolCallsAccum = [];
                }
                if (isset($delta['content']) && $delta['content'] !== '') {
                    $accumulated['content'] .= $delta['content'];
                    if (is_callable($onDelta)) $onDelta($delta['content'], $accumulated, false);
                }
                if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? 0;
                        if (!isset($toolCallsAccum[$idx])) {
                            $toolCallsAccum[$idx] = [
                                'id'       => $tc['id'] ?? '',
                                'type'     => $tc['type'] ?? 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];
                        }
                        if (isset($tc['id']) && $tc['id'] !== '') $toolCallsAccum[$idx]['id'] = $tc['id'];
                        if (isset($tc['function']['name'])) $toolCallsAccum[$idx]['function']['name'] .= $tc['function']['name'];
                        if (isset($tc['function']['arguments'])) $toolCallsAccum[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                    }
                }
            }
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$ok && $err) {
        return ['ok' => false, 'error' => '流式网络错误: ' . $err, 'data' => null];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => 'LLM 返回 HTTP ' . $code, 'data' => null];
    }
    if (!isset($accumulated)) {
        return ['ok' => false, 'error' => 'LLM 没返回任何内容', 'data' => null];
    }
    if (!empty($toolCallsAccum)) {
        $accumulated['tool_calls'] = array_values($toolCallsAccum);
    }
    return ['ok' => true, 'data' => $accumulated, 'error' => null];
}

/**
 * 主循环: 处理一条用户消息,跑完 tool calling,返回最终 assistant 文本 + 工具调用日志
 *
 * @param array  $user       当前登录用户
 * @param string $userMsg    用户本次消息
 * @param array  $opts       ['history_limit'=>30]
 * @return array [
 *    'ok'=>bool,
 *    'reply'=>string,           最终给用户看的回复
 *    'tool_calls_log'=>array,   本次所有工具调用摘要
 *    'usage'=>array,            token 用量
 *    'error'=>string
 * ]
 */
function runAssistantLoop($user, $userMsg, $opts = []) {
    $settings = getAiSettings($user['id']);
    if (empty($settings['enabled'])) {
        return ['ok' => false, 'error' => 'AI 助手未启用,请在 AI 设置中开启', 'reply' => '', 'tool_calls_log' => []];
    }
    if (empty($settings['api_base']) || empty($settings['model'])) {
        return ['ok' => false, 'error' => 'AI 助手未配置 API,请到 AI 设置填写', 'reply' => '', 'tool_calls_log' => []];
    }

    $limit    = (int)($opts['history_limit'] ?? 30);
    $taskId   = !empty($opts['task_id']) ? $opts['task_id'] : null;

    // 1) 写 user 消息
    addChatMessage($user['id'], 'user', $userMsg);

    // 2) 拼装 messages: system + 历史 + 新 user
    $history = getChatHistory($user['id'], $limit);
    $msgs = chatHistoryToMessages($history);
    $systemPrompt = !empty($settings['system_prompt']) ? $settings['system_prompt'] : getDefaultSystemPrompt($user);
    array_unshift($msgs, ['role' => 'system', 'content' => $systemPrompt]);

    // 3) 工具定义
    $tools = getAiTools();

    // 4) 循环: 调 LLM -> 决定是否 tool_calls -> 执行 -> 再调
    $toolLog = [];
    $maxSteps = 8;
    $step = 0;
    $finalReply = '';
    $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    $lastError = null;
    $cancelled = false;

    while ($step < $maxSteps) {
        // 中止检查:每轮 LLM 之前看一眼
        if ($taskId && aiTaskIsCancelled($taskId)) {
            $cancelled = true;
            break;
        }
        $step++;
        $resp = callLlm($settings, $msgs, $tools);
        // 调完后再检查一次(防止 LLM 刚返回完用户点取消)
        if ($taskId && aiTaskIsCancelled($taskId)) {
            $cancelled = true;
            // 已经写了 assistant 消息到历史?撤回掉
            // 简化:不撤回,在状态里标记 cancelled
            break;
        }
        if (!$resp['ok']) {
            $lastError = $resp['error'];
            break;
        }
        if (isset($resp['data']['usage'])) $usage = $resp['data']['usage'];
        $choice = $resp['data']['choices'][0] ?? null;
        if (!$choice) { $lastError = 'LLM 没返回 choices'; break; }
        $msg = $choice['message'] ?? [];
        $finishReason = $choice['finish_reason'] ?? 'stop';

        // 保存 assistant 消息(包括 tool_calls)
        $toolCallsRaw = $msg['tool_calls'] ?? null;
        $content = $msg['content'] ?? '';
        if ($content !== '' && $content !== null) $finalReply = $content;
        addChatMessage($user['id'], 'assistant', $content, $toolCallsRaw);

        // 推回 messages
        $asstMsg = ['role' => 'assistant', 'content' => $content];
        if (!empty($toolCallsRaw)) $asstMsg['tool_calls'] = $toolCallsRaw;
        $msgs[] = $asstMsg;

        // 没有 tool_calls = 结束
        if (empty($toolCallsRaw) || $finishReason !== 'tool_calls') {
            break;
        }

        // 执行每个 tool_call
        foreach ($toolCallsRaw as $tc) {
            // 每个工具调用前也检查
            if ($taskId && aiTaskIsCancelled($taskId)) { $cancelled = true; break 2; }

            $fnName = $tc['function']['name'] ?? '';
            $argsRaw = $tc['function']['arguments'] ?? '{}';
            $args = is_string($argsRaw) ? json_decode($argsRaw, true) : (array)$argsRaw;
            if (!is_array($args)) $args = [];

            $result = executeAiTool($fnName, $args, $user);
            $toolLog[] = [
                'tool'   => $fnName,
                'args'   => $args,
                'ok'     => $result['ok'] ?? false,
                'error'  => $result['error'] ?? null,
                'data'   => $result['data'] ?? null,
            ];

            // 工具结果以 tool 消息推回 LLM
            $toolResultStr = json_encode(
                $result['ok'] ? ['ok' => true, 'data' => $result['data']] : ['ok' => false, 'error' => $result['error']],
                JSON_UNESCAPED_UNICODE
            );
            addChatMessage($user['id'], 'tool', $toolResultStr, null, $tc['id'] ?? null, $fnName);
            $msgs[] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'] ?? null,
                'name'         => $fnName,
                'content'      => $toolResultStr,
            ];
        }
    }

    if ($cancelled) {
        if ($taskId) aiTaskMarkDone($taskId, ['cancelled' => true, 'reply_partial' => $finalReply]);
        return [
            'ok' => false,
            'cancelled' => true,
            'error' => '已中止',
            'reply' => $finalReply ?: '(已中止,无内容)',
            'tool_calls_log' => $toolLog,
            'usage' => $usage,
        ];
    }

    if ($lastError) {
        if ($taskId) aiTaskMarkError($taskId, $lastError);
        return [
            'ok' => false,
            'error' => $lastError,
            'reply' => $finalReply ?: '抱歉,调用 AI 出错了: ' . $lastError,
            'tool_calls_log' => $toolLog,
            'usage' => $usage,
        ];
    }

    if ($taskId) aiTaskMarkDone($taskId, ['reply_len' => strlen($finalReply)]);
    return [
        'ok' => true,
        'reply' => $finalReply ?: '(无回复)',
        'tool_calls_log' => $toolLog,
        'usage' => $usage,
        'error' => null,
    ];
}

/**
 * 流式版本: 与 runAssistantLoop 逻辑一致, 但 LLM 调用走流式(SSE),边读边回调
 *
 * @param array    $user
 * @param string   $userMsg       用户消息
 * @param array    $opts          ['history_limit'=>30, 'task_id'=>...]
 * @param callable $onEvent       function(string $eventType, array $data): void
 *                               事件类型:
 *                                 - 'start'           data: ['step'=>int]
 *                                 - 'content_delta'   data: ['delta'=>string, 'content'=>string (累积)]
 *                                 - 'tool_calls'      data: ['tool_calls'=>array, 'message'=>array]
 *                                 - 'tool_result'     data: ['tool'=>str, 'args'=>arr, 'ok'=>bool, 'data'=>arr, 'error'=>str]
 *                                 - 'final'           data: ['reply'=>string, 'usage'=>arr]
 *                                 - 'error'           data: ['message'=>str]
 *                                 - 'cancelled'       data: ['reply_partial'=>str]
 * @return array 同 runAssistantLoop
 */
function runAssistantLoopStream($user, $userMsg, $opts, $onEvent) {
    $settings = getAiSettings($user['id']);
    if (empty($settings['enabled'])) {
        $err = 'AI 助手未启用';
        if (is_callable($onEvent)) $onEvent('error', ['message' => $err]);
        return ['ok' => false, 'error' => $err, 'reply' => '', 'tool_calls_log' => []];
    }
    if (empty($settings['api_base']) || empty($settings['model'])) {
        $err = 'AI 助手未配置 API';
        if (is_callable($onEvent)) $onEvent('error', ['message' => $err]);
        return ['ok' => false, 'error' => $err, 'reply' => '', 'tool_calls_log' => []];
    }

    $limit  = (int)($opts['history_limit'] ?? 30);
    $taskId = !empty($opts['task_id']) ? $opts['task_id'] : null;
    $attachments = !empty($opts['attachments']) ? $opts['attachments'] : [];

    // 1) 写 user 消息(若带附件, 在内容里追加附件清单)
    $userContent = $userMsg;
    if (!empty($attachments)) {
        $lines = ['', '--- 已上传附件 ---'];
        foreach ($attachments as $a) {
            $line = '📎 ' . $a['original_name'] . ' (' . $a['filename'] . ', ' . ai_format_size((int)$a['file_size']) . ')';
            if (!empty($a['extracted_text'])) {
                $ext = mb_substr($a['extracted_text'], 0, 500);
                $line .= "\n   内容预览:\n   " . str_replace("\n", "\n   ", $ext);
            }
            $lines[] = $line;
        }
        $lines[] = '--- (附件已保存, 如需让 AI 引用请在消息中说明) ---';
        $userContent .= "\n" . implode("\n", $lines);
    }
    addChatMessage($user['id'], 'user', $userContent, null, null, null,
        !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null);

    // 2) 拼 messages
    $history = getChatHistory($user['id'], $limit);
    $msgs = chatHistoryToMessages($history);
    $systemPrompt = !empty($settings['system_prompt']) ? $settings['system_prompt'] : getDefaultSystemPrompt($user);
    array_unshift($msgs, ['role' => 'system', 'content' => $systemPrompt]);

    // 3) 工具
    $tools = getAiTools();

    $toolLog = [];
    $maxSteps = 8;
    $step = 0;
    $finalReply = '';
    $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    $lastError = null;
    $cancelled = false;

    while ($step < $maxSteps) {
        if ($taskId && aiTaskIsCancelled($taskId)) { $cancelled = true; break; }
        $step++;
        if (is_callable($onEvent)) $onEvent('start', ['step' => $step]);

        $streamedContent = '';
        $streamedMsg = null;
        $streamError = null;

        $resp = callLlmStream($settings, $msgs, $tools, function($delta, $fullMsg, $isFinal) use ($onEvent, &$streamedContent, &$streamedMsg) {
            $streamedContent = $fullMsg['content'] ?? '';
            $streamedMsg = $fullMsg;
            if ($delta !== '') {
                if (is_callable($onEvent)) $onEvent('content_delta', ['delta' => $delta, 'content' => $streamedContent]);
            }
        });

        if ($taskId && aiTaskIsCancelled($taskId)) { $cancelled = true; break; }

        if (!$resp['ok']) {
            $lastError = $resp['error'];
            if (is_callable($onEvent)) $onEvent('error', ['message' => $lastError]);
            break;
        }
        $msg = $resp['data'];
        $toolCallsRaw = $msg['tool_calls'] ?? null;
        $content = $msg['content'] ?? '';
        if ($content !== '' && $content !== null) $finalReply = $content;
        addChatMessage($user['id'], 'assistant', $content, $toolCallsRaw);

        if (is_callable($onEvent) && !empty($toolCallsRaw)) {
            $onEvent('tool_calls', ['tool_calls' => $toolCallsRaw, 'message' => $msg]);
        }

        $asstMsg = ['role' => 'assistant', 'content' => $content];
        if (!empty($toolCallsRaw)) $asstMsg['tool_calls'] = $toolCallsRaw;
        $msgs[] = $asstMsg;

        if (empty($toolCallsRaw)) break;

        foreach ($toolCallsRaw as $tc) {
            if ($taskId && aiTaskIsCancelled($taskId)) { $cancelled = true; break 2; }
            $fnName = $tc['function']['name'] ?? '';
            $argsRaw = $tc['function']['arguments'] ?? '{}';
            $args = is_string($argsRaw) ? json_decode($argsRaw, true) : (array)$argsRaw;
            if (!is_array($args)) $args = [];

            $result = executeAiTool($fnName, $args, $user);
            $toolLog[] = [
                'tool'   => $fnName,
                'args'   => $args,
                'ok'     => $result['ok'] ?? false,
                'error'  => $result['error'] ?? null,
                'data'   => $result['data'] ?? null,
            ];
            if (is_callable($onEvent)) {
                $onEvent('tool_result', [
                    'tool'  => $fnName, 'args' => $args,
                    'ok'    => $result['ok'] ?? false,
                    'data'  => $result['data'] ?? null,
                    'error' => $result['error'] ?? null,
                ]);
            }
            $toolResultStr = json_encode(
                $result['ok'] ? ['ok' => true, 'data' => $result['data']] : ['ok' => false, 'error' => $result['error']],
                JSON_UNESCAPED_UNICODE
            );
            addChatMessage($user['id'], 'tool', $toolResultStr, null, $tc['id'] ?? null, $fnName);
            $msgs[] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'] ?? null,
                'name'         => $fnName,
                'content'      => $toolResultStr,
            ];
        }
    }

    if ($cancelled) {
        if ($taskId) aiTaskMarkDone($taskId, ['cancelled' => true, 'reply_partial' => $finalReply]);
        if (is_callable($onEvent)) $onEvent('cancelled', ['reply_partial' => $finalReply]);
        return ['ok' => false, 'cancelled' => true, 'error' => '已中止', 'reply' => $finalReply ?: '(已中止,无内容)', 'tool_calls_log' => $toolLog, 'usage' => $usage];
    }

    if ($lastError) {
        if ($taskId) aiTaskMarkError($taskId, $lastError);
        return ['ok' => false, 'error' => $lastError, 'reply' => $finalReply ?: '抱歉,调用 AI 出错了: ' . $lastError, 'tool_calls_log' => $toolLog, 'usage' => $usage];
    }

    if ($taskId) aiTaskMarkDone($taskId, ['reply_len' => strlen($finalReply)]);
    if (is_callable($onEvent)) $onEvent('final', ['reply' => $finalReply, 'usage' => $usage, 'tool_calls_log' => $toolLog]);
    return ['ok' => true, 'reply' => $finalReply ?: '(无回复)', 'tool_calls_log' => $toolLog, 'usage' => $usage, 'error' => null];
}

/**
 * 文件大小可读化 (AI 内部使用,避免和业务函数重名)
 */
if (!function_exists('ai_format_size')) {
    function ai_format_size($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
