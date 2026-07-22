<?php
// 云智计算 全局配置文件 包含端点 密钥 模型 系统提示词 记忆 上传与安全配置
return [
    'api' => [
        'url'             => 'https://yunzhiapi.cn/v1/chat/completions',
        'key'             => 'sk-NiU5M0YpHEEOZ1MkkId6cOq5euaZ8FHiXrtUQEIuXsA2uwuv',
        'timeout'         => 120,
        'connect_timeout' => 15,
    ],
    'security' => [
        'allowed_origin'      => 'https://yunzhiapi.cn',
        'default_uid'         => '000000',
        'max_question_length' => 30000,
        'clean_token'         => '',
        // 限流配置 用于防止恶意请求和资源滥用
        'rate_limit'          => [
            'enabled'  => true,
            'window'   => 60,
            'max_reqs' => 30,
            'dir'      => __DIR__ . '/file/ratelimit/',
        ],
        // 日志配置 用于记录错误和审计
        'log'                 => [
            'enabled' => true,
            'dir'     => __DIR__ . '/file/log/',
            'retention_days' => 7,
        ],
    ],
    'memory' => [
        'dir'            => __DIR__ . '/file/',
        'code_dir'       => __DIR__ . '/file/code/',
        'max_rounds'     => 30,
        'shared_modules' => ['default', 'thinking', 'websearch', 'writing', 'answer', 'ocr'],
    ],
    'upload' => [
        'dir'         => __DIR__ . '/file/',
        'base_url'    => 'https://yunzhiapi.cn/chat/file/',
        'max_size'    => 10485760,
        'allowed_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'pdf'],
    ],
    'modules' => [
        'default' => [
            'model'      => 'GLM-Z1-0414',
            'max_tokens' => 10000,
            'system'     => '你是由云智计算训练和开发的Three多模态大模型，支持联网搜索和记忆对话，官网是https://yunzhiapi.cn/，请参考数据回答。',
        ],
        'thinking' => [
            'model'      => 'DeepSeek-R1',
            'max_tokens' => 10000,
            'system'     => '你是由云智计算训练和开发的Three多模态大模型，支持联网搜索和记忆对话，官网是https://yunzhiapi.cn/，请参考数据回答。',
        ],
        'websearch' => [
            'model'      => 'DeepSeek-V4-Flash',
            'max_tokens' => 10000,
            'system'     => '你是由云智计算训练和开发的Three多模态大模型，支持联网搜索和记忆对话，官网是https://yunzhiapi.cn/，请参考数据回答。',
        ],
        'writing' => [
            'model'      => 'Agnes-2.0-Flash',
            'max_tokens' => 10000,
            'system'     => '你是由云智计算训练和开发的TWOS模型，是一个全能的写作专家，精通所有文体创作（包括但不限于作文、散文、小说、新闻、诗词等），能根据我的需求生成高质量内容，提供结构建议、语言润色和创意灵感，并适应不同风格与格式要求。',
        ],
        'answer' => [
            'model'      => 'GPT-OSS-120B',
            'max_tokens' => 8000,
            'system'     => '你是由云智计算研发的AI解题助手，名字叫云智Three，专注讲解所有学科领域的题目（数学、物理、政治、编程、语言等），对于开放性的法律、道德、伦理、哲学、社会、英语、翻译、生活常识类的问题可以按照你的理解进行解答。你的核心任务是：严格围绕问题本身，提供专业、详细的解答，拒绝回答任何与问题无关的内容（如闲聊、情感问题等）。回答需遵循以下格式：1.解答过程必须完整包裹在<Think></Think>标签内；①题目分析：拆解问题关键信息与考察点；②逻辑推导：分步骤展示思考路径，标注公式/定理来源；③验证过程：逐步检查答案和步骤的合理性，并提供最好的验证方法；在</Think>标签末尾用【】来标出解题答案（例如【解题答案：A】）。2.语言风格要口语化，模仿老师给学生授课，让我能够看懂解答的内容，复杂步骤需附加详细的注释3.若用户输入非题目内容，回复：∑(O_O；)抱歉，您的提问缺少核心信息，只有提供完整的题目之后才能帮您答疑解惑哦！，不主动补充课外知识，除非直接影响解题逻辑。4.若题目信息不全，要求用户补充更加详细的信息。请严格按此模式执行，确保答案精确、过程可追溯。',
        ],
        'code' => [
            'model'      => 'DeepSeek-V4-Flash',
            'max_tokens' => 5000,
            'system'     => '你是由云智计算训练和开发的TWOS模型，是一位资深的全栈程序员，精通多种编程语言（如Python、JavaScript、Java、C++、Go、Rust等），熟悉主流框架（如React、Vue、Django、Spring、Express等），并具备扎实的计算机科学基础（数据结构、算法、操作系统、网络、数据库等）。你擅长：1.阅读、理解并优化现有代码，2.快速定位并修复 Bug，3.编写高效、可维护、可扩展的代码，4.提供最佳实践和设计模式建议，5.进行系统架构设计与性能调优，6.编写清晰的技术文档和注释。在提供代码时，确保语法正确，排版规范。请用专业、严谨并且易懂的方式简短的回答。',
        ],
        'codeOptimize' => [
            'model'      => 'DeepSeek-V4-Flash',
            'max_tokens' => 10000,
            'system'     => '你是一个提示词优化大师，请根据我输入的模糊需求进行更新和完善，确保最后你输出的提示词准确、专业并且详细，不改变我原来的意思，无需进行格外的解释和询问。',
            'no_memory'  => true,
        ],
        'ocr' => [
            'model'      => 'Qwen-3.5-Flash',
            'max_tokens' => 10000,
            'multimodal' => true,
        ],
        'translate' => [
            'model'       => 'GLM-Z1-0414',
            'max_tokens'  => 4096,
            'temperature' => 0.1,
            'no_memory'   => true,
        ],
        'image' => [
            'model'     => 'GPT-Image-2',
            'timeout'   => 300,
            'dir'       => __DIR__ . '/file/image/',
            'base_url'  => 'https://yunzhiapi.cn/chat/file/image/',
            'max_size'  => 20971520,
            'no_memory' => true,
        ],
    ],
    'languages' => [
        'en' => '英语', 'zh-cn' => '简体中文', 'zh-tw' => '繁體中文', 'ja' => '日语', 'ko' => '韩语',
        'fr' => '法语', 'de' => '德语', 'es' => '西班牙语', 'ru' => '俄语', 'pt' => '葡萄牙语',
        'it' => '意大利语', 'ar' => '阿拉伯语', 'th' => '泰语', 'vi' => '越南语', 'wyw' => '文言文',
        'yue' => '粤语', 'ms' => '马来语', 'id' => '印尼语', 'hi' => '印地语', 'bn' => '孟加拉语',
        'fa' => '波斯语', 'tr' => '土耳其语', 'he' => '希伯来语', 'ur' => '乌尔都语', 'ta' => '泰米尔语',
        'te' => '泰卢固语', 'mr' => '马拉地语', 'gu' => '古吉拉特语', 'kn' => '卡纳达语', 'ml' => '马拉雅拉姆语',
        'pa' => '旁遮普语', 'my' => '缅甸语', 'km' => '高棉语', 'lo' => '老挝语', 'ne' => '尼泊尔语',
        'si' => '僧伽罗语', 'nl' => '荷兰语', 'sv' => '瑞典语', 'no' => '挪威语', 'da' => '丹麦语',
        'fi' => '芬兰语', 'pl' => '波兰语', 'cs' => '捷克语', 'sk' => '斯洛伐克语', 'hu' => '匈牙利语',
        'ro' => '罗马尼亚语', 'bg' => '保加利亚语', 'el' => '希腊语', 'uk' => '乌克兰语', 'sr' => '塞尔维亚语',
        'hr' => '克罗地亚语', 'sl' => '斯洛文尼亚语', 'et' => '爱沙尼亚语', 'lv' => '拉脱维亚语', 'lt' => '立陶宛语',
        'is' => '冰岛语', 'ga' => '爱尔兰语', 'cy' => '威尔士语', 'ca' => '加泰罗尼亚语', 'eu' => '巴斯克语',
        'gl' => '加利西亚语', 'af' => '南非荷兰语', 'sw' => '斯瓦希里语', 'ha' => '豪萨语', 'ig' => '伊博语',
        'yo' => '约鲁巴语', 'zu' => '祖鲁语', 'xh' => '科萨语', 'mg' => '马尔加什语', 'am' => '阿姆哈拉语',
        'so' => '索马里语', 'mn' => '蒙古语', 'hy' => '亚美尼亚语', 'ka' => '格鲁吉亚语', 'az' => '阿塞拜疆语',
        'kk' => '哈萨克语', 'uz' => '乌兹别克语', 'tg' => '塔吉克语', 'be' => '白俄罗斯语', 'eo' => '世界语',
    ],
];
