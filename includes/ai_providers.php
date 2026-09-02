<?php
/**
 * AI 助手 - 预设服务提供方与模型清单
 * AI Providers & Models Catalog
 *
 * 用户在 AI 设置里下拉选择,无需手填 URL / 模型名
 */

/**
 * 预设服务提供方
 * @return array
 *   每个元素: ['key'=>..., 'label'=>..., 'api_base'=>..., 'default_model'=>..., 'note'=>..., 'models'=>[...]]
 */
function getAiProviders() {
    return [
        'ollama' => [
            'key'           => 'ollama',
            'label'         => '🦙 Ollama (推荐本地)',
            'api_base'      => 'http://localhost:11434/v1',
            'default_model' => 'qwen2.5:7b',
            'note'          => '本地 Ollama 服务(API Key 留空)',
            'models' => [
                'qwen2.5:7b'           => 'Qwen 2.5 7B (中文强)',
                'qwen2.5:14b'          => 'Qwen 2.5 14B',
                'qwen2.5:32b'          => 'Qwen 2.5 32B',
                'llama3.1:8b'          => 'Llama 3.1 8B',
                'llama3.2:3b'          => 'Llama 3.2 3B (轻量)',
                'llama3.3:70b'         => 'Llama 3.3 70B',
                'deepseek-coder:6.7b'  => 'DeepSeek Coder 6.7B (代码)',
                'deepseek-r1:7b'       => 'DeepSeek R1 7B (推理)',
                'deepseek-r1:14b'      => 'DeepSeek R1 14B (推理)',
                'codellama:7b'         => 'CodeLlama 7B (代码)',
                'mistral:7b'           => 'Mistral 7B',
                'gemma2:9b'            => 'Gemma 2 9B',
                'gemma2:27b'           => 'Gemma 2 27B',
                'phi3:3.8b'            => 'Phi-3 3.8B (微软)',
                'phi3:14b'             => 'Phi-3 14B',
                'llava:7b'             => 'LLaVA 7B (多模态)',
                '__custom__'           => '自定义模型...',
            ],
        ],
        'llama_cpp' => [
            'key'           => 'llama_cpp',
            'label'         => '🦙 llama.cpp server',
            'api_base'      => 'http://localhost:8080/v1',
            'default_model' => 'local-model',
            'note'          => 'llama.cpp 编译的 server,默认 8080 端口',
            'models' => [
                'local-model'           => '使用 server 端加载的模型',
                'qwen2.5-7b-instruct-q4_k_m.gguf' => 'Qwen 2.5 7B Instruct (Q4_K_M)',
                'qwen2.5-14b-instruct-q4_k_m.gguf'=> 'Qwen 2.5 14B Instruct (Q4_K_M)',
                'llama-3.1-8b-instruct-q4_k_m.gguf'=> 'Llama 3.1 8B (Q4_K_M)',
                'mistral-7b-instruct-q4_k_m.gguf'  => 'Mistral 7B Instruct (Q4_K_M)',
                'gemma-2-9b-it-q4_k_m.gguf'       => 'Gemma 2 9B IT (Q4_K_M)',
                'phi-3-mini-4k-instruct-q4.gguf'   => 'Phi-3 Mini (Q4)',
                'codellama-7b-instruct-q4_k_m.gguf'=> 'CodeLlama 7B (Q4_K_M)',
                '__custom__'           => '自定义模型...',
            ],
        ],
        'lm_studio' => [
            'key'           => 'lm_studio',
            'label'         => '🎛️ LM Studio',
            'api_base'      => 'http://localhost:1234/v1',
            'default_model' => 'qwen2.5-7b-instruct',
            'note'          => 'LM Studio 本地服务,API Key 填 lm-studio',
            'models' => [
                'qwen2.5-7b-instruct'  => 'Qwen 2.5 7B Instruct',
                'qwen2.5-14b-instruct' => 'Qwen 2.5 14B Instruct',
                'llama-3.1-8b-instruct'=> 'Llama 3.1 8B Instruct',
                'mistral-7b-instruct'  => 'Mistral 7B Instruct',
                'gemma-2-9b-instruct'  => 'Gemma 2 9B IT',
                'phi-3-mini-4k-instruct'=> 'Phi-3 Mini 4K',
                'codellama-7b-instruct'=> 'CodeLlama 7B',
                'local-model'           => '当前 LM Studio 加载的模型',
                '__custom__'           => '自定义模型...',
            ],
        ],
        'vllm' => [
            'key'           => 'vllm',
            'label'         => '⚡ vLLM',
            'api_base'      => 'http://localhost:8000/v1',
            'default_model' => 'Qwen/Qwen2.5-7B-Instruct',
            'note'          => 'vLLM OpenAI 兼容服务',
            'models' => [
                'Qwen/Qwen2.5-7B-Instruct'  => 'Qwen 2.5 7B Instruct',
                'Qwen/Qwen2.5-14B-Instruct' => 'Qwen 2.5 14B Instruct',
                'meta-llama/Llama-3.1-8B-Instruct' => 'Llama 3.1 8B',
                'mistralai/Mistral-7B-Instruct-v0.3'=> 'Mistral 7B',
                'google/gemma-2-9b-it'      => 'Gemma 2 9B IT',
                'microsoft/Phi-3-mini-4k-instruct' => 'Phi-3 Mini',
                '__custom__'                 => '自定义模型...',
            ],
        ],
        'xinference' => [
            'key'           => 'xinference',
            'label'         => '🔥 Xinference',
            'api_base'      => 'http://localhost:9997/v1',
            'default_model' => 'qwen2.5-instruct',
            'note'          => 'Xinference 分布式推理服务',
            'models' => [
                'qwen2.5-instruct'   => 'Qwen 2.5 Instruct',
                'qwen2.5-14b-instruct'=> 'Qwen 2.5 14B',
                'llama-3.1-8b-instruct' => 'Llama 3.1 8B',
                'mistral-7b-instruct' => 'Mistral 7B',
                '__custom__'          => '自定义模型...',
            ],
        ],
        'openai' => [
            'key'           => 'openai',
            'label'         => '☁️ OpenAI 官方',
            'api_base'      => 'https://api.openai.com/v1',
            'default_model' => 'gpt-4o-mini',
            'note'          => 'OpenAI 官方 API,需要有效 API Key',
            'models' => [
                'gpt-4o'         => 'GPT-4o (多模态旗舰)',
                'gpt-4o-mini'    => 'GPT-4o mini (便宜快速)',
                'gpt-4-turbo'    => 'GPT-4 Turbo',
                'gpt-3.5-turbo'  => 'GPT-3.5 Turbo (便宜)',
                'o1-preview'     => 'o1-preview (推理)',
                'o1-mini'        => 'o1-mini (推理便宜)',
                '__custom__'     => '自定义模型...',
            ],
        ],
        'custom' => [
            'key'           => 'custom',
            'label'         => '🔧 自定义 (OpenAI 兼容)',
            'api_base'      => '',
            'default_model' => '',
            'note'          => '任意 OpenAI 兼容的服务,自己填 URL 和模型名',
            'models' => [
                '__custom__'     => '自定义模型名',
            ],
        ],
    ];
}

/**
 * 推断当前 ai_base 属于哪个 provider(用于回显时默认选中)
 * 简单做法:用字符串前缀匹配
 */
function detectProviderFromApiBase($apiBase) {
    if (empty($apiBase)) return 'custom';
    $base = strtolower(rtrim($apiBase, '/'));
    if (strpos($base, '11434') !== false) return 'ollama';
    if (strpos($base, '1234')  !== false) return 'lm_studio';
    if (strpos($base, '8080')  !== false) return 'llama_cpp';
    if (strpos($base, '8000')  !== false) return 'vllm';
    if (strpos($base, '9997')  !== false) return 'xinference';
    if (strpos($base, 'api.openai.com') !== false) return 'openai';
    return 'custom';
}
