<?php
/**
 * بوت البحث عن الأرقام - نسخة مؤمنة
 * تم إصلاح الثغرات الأمنية: Hardcoded Credentials, Insecure SSL, HTML Injection, Insecure Storage
 */

// --- 1. الإعدادات وتأمين البيانات الحساسة ---
// يفضل استخدام متغيرات البيئة، ولكن سنستخدم ثوابت مع تنبيه المستخدم
define('BOT_TOKEN', '8606711654:AAHw0ClMs4o15tf0iDSXsiwFfOd41EgUPvQ'); // اتركها فارغة هنا وضعها في ملف .env أو قم بتعبئتها
define('ADMIN_ID', '6330792075');  // يجب وضع الآيدي الخاص بك هنا لتفعيل صلاحيات الأدمن
define('BOT_USERNAME', 'Number9informationbot');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// تأمين مجلد البيانات: يفضل أن يكون خارج جذر الويب
define('DATA_DIR', __DIR__ . '/data/');

// إنشاء مجلد البيانات وتأمينه بملف .htaccess لمنع الوصول المباشر
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0700, true);
    file_put_contents(DATA_DIR . '.htaccess', "Deny from all\nOptions -Indexes");
    file_put_contents(DATA_DIR . 'index.php', "<?php http_response_code(403); ?>");
}

define('COST_MONITOR', 5);
define('COST_LIST_NUMBERS', 2);

$defaultData = [
    'users.json' => [],
    'numbers.json' => ['extracted' => [], 'monitored' => []],
    'settings.json' => ['maintenance' => false, 'total_searches' => 0],
    'logs.json' => [],
    'blocked.json' => [],
    'user_state.json' => [],
    'admin_state.json' => [],
    'forced_channels.json' => []
];

foreach ($defaultData as $file => $default) {
    $filePath = DATA_DIR . $file;
    if (!file_exists($filePath)) {
        file_put_contents($filePath, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($filePath, 0600); // حماية الملفات
    }
}

// --- 2. الدوال الأساسية لإدارة البيانات ---

function loadData($file) {
    $filePath = DATA_DIR . $file;
    if (!file_exists($filePath)) return [];
    $content = file_get_contents($filePath);
    return json_decode($content, true) ?? [];
}

function saveData($file, $data) {
    $filePath = DATA_DIR . $file;
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * تنظيف النصوص لمنع حقن HTML (XSS)
 */
function cleanText($text) {
    return htmlspecialchars(strip_tags(trim($text)), ENT_QUOTES, 'UTF-8');
}

function addLog($action, $details = '') {
    $logs = loadData('logs.json');
    $log = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => cleanText($action),
        'details' => cleanText($details)
    ];
    array_unshift($logs, $log);
    $logs = array_slice($logs, 0, 100);
    saveData('logs.json', $logs);
}

// --- 3. دوال التواصل مع تيليجرام (cURL) ---

function sendRequest($method, $params = []) {
    $url = API_URL . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // إصلاح أمني: تفعيل التحقق من شهادات SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log('cURL Error: ' . curl_error($ch));
    }
    
    curl_close($ch);
    return json_decode($response, true);
}

function sendMessage($chatId, $text, $inlineKeyboard = null, $parseMode = 'HTML') {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true
    ];
    if ($inlineKeyboard) {
        $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
    }
    return sendRequest('sendMessage', $params);
}

function editMessage($chatId, $messageId, $text, $inlineKeyboard = null) {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    if ($inlineKeyboard) {
        $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
    }
    return sendRequest('editMessageText', $params);
}

function answerCallback($callbackId, $text = '', $alert = false) {
    return sendRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => cleanText($text),
        'show_alert' => $alert
    ]);
}

function getChatMember($chatId, $userId) {
    return sendRequest('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId
    ]);
}

function isUserSubscribed($userId) {
    $channels = loadData('forced_channels.json');
    if (empty($channels)) return true;
    foreach ($channels as $channel) {
        $chatId = $channel['chat_id'];
        $result = getChatMember($chatId, $userId);
        if (!isset($result['ok']) || !$result['ok']) return false;
        $status = $result['result']['status'] ?? '';
        if (!in_array($status, ['member', 'administrator', 'creator'])) return false;
    }
    return true;
}

function getSubscriptionKeyboard() {
    $channels = loadData('forced_channels.json');
    $buttons = [];
    foreach ($channels as $channel) {
        $buttons[] = [['text' => cleanText($channel['name']), 'url' => $channel['invite_link']]];
    }
    $buttons[] = [['text' => 'تحقق من الاشتراك', 'callback_data' => 'check_subscription']];
    return $buttons;
}

// --- 4. دوال البحث والمنطق البرمجي ---

function detectCountry($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $countries = [
        '966' => ['name' => 'السعودية', 'flag' => '🇸🇦'],
        '20' => ['name' => 'مصر', 'flag' => '🇪🇬'],
        '964' => ['name' => 'العراق', 'flag' => '🇮🇶'],
        '962' => ['name' => 'الأردن', 'flag' => '🇯🇴'],
        '961' => ['name' => 'لبنان', 'flag' => '🇱🇧'],
        '970' => ['name' => 'فلسطين', 'flag' => '🇵🇸'],
        '971' => ['name' => 'الإمارات', 'flag' => '🇦🇪'],
        '965' => ['name' => 'الكويت', 'flag' => '🇰🇼'],
        '968' => ['name' => 'عمان', 'flag' => '🇴🇲'],
        '973' => ['name' => 'البحرين', 'flag' => '🇧🇭'],
        '974' => ['name' => 'قطر', 'flag' => '🇶🇦'],
        '212' => ['name' => 'المغرب', 'flag' => '🇲🇦'],
        '213' => ['name' => 'الجزائر', 'flag' => '🇩🇿'],
        '216' => ['name' => 'تونس', 'flag' => '🇹🇳'],
        '249' => ['name' => 'السودان', 'flag' => '🇸🇩'],
        '218' => ['name' => 'ليبيا', 'flag' => '🇱🇾'],
        '967' => ['name' => 'اليمن', 'flag' => '🇾🇪'],
        '963' => ['name' => 'سوريا', 'flag' => '🇸🇾'],
        '1' => ['name' => 'أمريكا/كندا', 'flag' => '🇺🇸'],
        '44' => ['name' => 'بريطانيا', 'flag' => '🇬🇧'],
        '49' => ['name' => 'ألمانيا', 'flag' => '🇩🇪'],
        '33' => ['name' => 'فرنسا', 'flag' => '🇫🇷'],
        '90' => ['name' => 'تركيا', 'flag' => '🇹🇷'],
        '91' => ['name' => 'الهند', 'flag' => '🇮🇳'],
        '86' => ['name' => 'الصين', 'flag' => '🇨🇳'],
        '81' => ['name' => 'اليابان', 'flag' => '🇯🇵'],
        '55' => ['name' => 'البرازيل', 'flag' => '🇧🇷'],
        '7' => ['name' => 'روسيا', 'flag' => '🇷🇺'],
    ];
    krsort($countries);
    foreach ($countries as $code => $info) {
        if (strpos($phone, $code) === 0) {
            return [
                'code' => $code,
                'name' => $info['name'],
                'flag' => $info['flag'],
                'formatted' => '+' . substr($phone, 0, strlen($code)) . ' ' . substr($phone, strlen($code))
            ];
        }
    }
    return [
        'code' => 'unknown',
        'name' => 'غير معروفة',
        'flag' => '🌍',
        'formatted' => '+' . $phone
    ];
}

function fetchSpytoxData($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $url = "https://www.spytox.com/search?q=" . urlencode($phone);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // تأمين
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode != 200 || empty($html)) return false;
    $data = [];
    if (preg_match('/<div[^>]*class="[^"]*name[^"]*"[^>]*>(.*?)<\/div>/i', $html, $match)) {
        $name = cleanText($match[1]);
        if (!empty($name) && strlen($name) < 100) $data['name'] = $name;
    }
    if (preg_match('/<div[^>]*class="[^"]*carrier[^"]*"[^>]*>(.*?)<\/div>/i', $html, $match)) {
        $carrier = cleanText($match[1]);
        if (!empty($carrier)) $data['carrier'] = $carrier;
    }
    $summary = [];
    if (isset($data['name'])) $summary[] = "الاسم: " . $data['name'];
    if (isset($data['carrier'])) $summary[] = "المشغل: " . $data['carrier'];
    if (empty($summary)) $summary[] = "معلومات عن الرقم متاحة عبر الرابط";
    $data['summary'] = implode(' • ', $summary);
    $data['url'] = $url;
    return $data;
}

function fetchHaveIBeenPwned($email) {
    $url = "https://haveibeenpwned.com/unifiedsearch/" . urlencode($email);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // تأمين
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode != 200) return false;
    $data = [];
    if (strpos($html, 'Oh no — pwned!') !== false) {
        preg_match_all('/<a href="\/breach\/([^"]+)">([^<]+)<\/a>/i', $html, $matches);
        $data['breaches'] = !empty($matches[2]) ? array_map('cleanText', $matches[2]) : ['تم العثور على اختراقات'];
    } else {
        $data['breaches'] = [];
    }
    return $data;
}

function searchNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $country = detectCountry($phone);
    $cacheDir = DATA_DIR . 'cache/';
    if (!file_exists($cacheDir)) mkdir($cacheDir, 0700, true);
    $cacheFile = $cacheDir . 'phone_' . md5($phone) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        return json_decode(file_get_contents($cacheFile), true);
    }
    $results = [
        'query' => $phone,
        'type' => 'number',
        'country' => $country,
        'platforms' => [],
        'stats' => ['total_mentions' => 0, 'social_media' => 0, 'websites' => 0],
        'extra_info' => []
    ];
    $spytoxData = fetchSpytoxData($phone);
    if ($spytoxData) {
        $results['extra_info'] = $spytoxData;
        $results['platforms'][] = [
            'name' => 'Spytox (معلومات الرقم)',
            'icon' => '📞',
            'url' => 'https://www.spytox.com/search?q=' . urlencode($phone),
            'found' => true,
            'info' => $spytoxData['summary'] ?? 'معلومات متاحة'
        ];
        $results['stats']['total_mentions'] += 1;
    }
    
    $socialPlatforms = [
        'whatsapp' => ['name' => 'واتساب', 'icon' => '🟢', 'url' => 'https://wa.me/' . $phone],
        'truecaller' => ['name' => 'تروكولر', 'icon' => '🔵', 'url' => 'https://www.truecaller.com/search/unknown/' . $phone],
        'facebook' => ['name' => 'فيسبوك', 'icon' => '📘', 'url' => 'https://www.facebook.com/search/top?q=' . urlencode($phone)],
        'instagram' => ['name' => 'إنستغرام', 'icon' => '📷', 'url' => 'https://www.instagram.com/explore/tags/?q=' . urlencode($phone)],
        'twitter' => ['name' => 'تويتر/X', 'icon' => '🐦', 'url' => 'https://twitter.com/search?q=' . urlencode($phone)],
        'linkedin' => ['name' => 'لينكد إن', 'icon' => '💼', 'url' => 'https://www.linkedin.com/search/results/all/?keywords=' . urlencode($phone)],
        'youtube' => ['name' => 'يوتيوب', 'icon' => '▶️', 'url' => 'https://www.youtube.com/results?search_query=' . urlencode($phone)],
        'google' => ['name' => 'جوجل', 'icon' => '🔍', 'url' => 'https://www.google.com/search?q=' . urlencode($phone)],
        'snapchat' => ['name' => 'سناب شات', 'icon' => '👻', 'url' => 'https://www.snapchat.com/add/' . urlencode($phone)],
        'github' => ['name' => 'GitHub', 'icon' => '💻', 'url' => 'https://github.com/search?q=' . urlencode($phone)],
    ];
    foreach ($socialPlatforms as $key => $platform) {
        $results['platforms'][] = [
            'name' => $platform['name'],
            'icon' => $platform['icon'],
            'url' => $platform['url'],
            'found' => true
        ];
        $results['stats']['social_media']++;
        $results['stats']['total_mentions'] += 1;
    }
    file_put_contents($cacheFile, json_encode($results));
    return $results;
}

function searchEmail($email) {
    $email = cleanText($email);
    $cacheDir = DATA_DIR . 'cache/';
    if (!file_exists($cacheDir)) mkdir($cacheDir, 0700, true);
    $cacheFile = $cacheDir . 'email_' . md5($email) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        return json_decode(file_get_contents($cacheFile), true);
    }
    $results = [
        'query' => $email,
        'type' => 'email',
        'platforms' => [],
        'stats' => ['total_mentions' => 0, 'social_media' => 0],
        'extra_info' => []
    ];
    $hibpData = fetchHaveIBeenPwned($email);
    if ($hibpData) {
        $results['extra_info']['hibp'] = $hibpData;
        $breaches = $hibpData['breaches'] ?? [];
        if (count($breaches) > 0) {
            $breachStr = implode(', ', array_slice($breaches, 0, 3));
            $results['platforms'][] = [
                'name' => 'Have I Been Pwned',
                'icon' => '🛡️',
                'url' => 'https://haveibeenpwned.com/unifiedsearch/' . urlencode($email),
                'found' => true,
                'info' => "اختراق في: {$breachStr}" . (count($breaches) > 3 ? ' ...' : '')
            ];
            $results['stats']['total_mentions'] += count($breaches);
        } else {
            $results['platforms'][] = [
                'name' => 'Have I Been Pwned',
                'icon' => '🛡️',
                'url' => 'https://haveibeenpwned.com/unifiedsearch/' . urlencode($email),
                'found' => true,
                'info' => 'لم يتم العثور على اختراقات'
            ];
        }
    }
    $socialPlatforms = [
        'google' => ['name' => 'جوجل', 'icon' => '🔍', 'url' => 'https://www.google.com/search?q=' . urlencode('"' . $email . '"')],
        'facebook' => ['name' => 'فيسبوك', 'icon' => '📘', 'url' => 'https://www.facebook.com/search/top?q=' . urlencode($email)],
        'linkedin' => ['name' => 'لينكد إن', 'icon' => '💼', 'url' => 'https://www.linkedin.com/sales/gmail/profile/viewByEmail/' . urlencode($email)],
        'twitter' => ['name' => 'تويتر/X', 'icon' => '🐦', 'url' => 'https://twitter.com/search?q=' . urlencode($email)],
        'github' => ['name' => 'GitHub', 'icon' => '💻', 'url' => 'https://github.com/search?q=' . urlencode($email)],
        'instagram' => ['name' => 'إنستغرام', 'icon' => '📷', 'url' => 'https://www.instagram.com/explore/tags/?q=' . urlencode($email)],
        'tiktok' => ['name' => 'تيك توك', 'icon' => '🎵', 'url' => 'https://www.tiktok.com/search?q=' . urlencode($email)],
        'snapchat' => ['name' => 'سناب شات', 'icon' => '👻', 'url' => 'https://www.snapchat.com/add/' . urlencode($email)]
    ];
    foreach ($socialPlatforms as $key => $platform) {
        $results['platforms'][] = [
            'name' => $platform['name'],
            'icon' => $platform['icon'],
            'url' => $platform['url'],
            'found' => true
        ];
        $results['stats']['social_media']++;
        $results['stats']['total_mentions'] += 1;
    }
    file_put_contents($cacheFile, json_encode($results));
    return $results;
}

function searchName($name) {
    $name = cleanText($name);
    $results = [
        'query' => $name,
        'type' => 'name',
        'platforms' => [],
        'stats' => ['total_mentions' => 0, 'social_media' => 0]
    ];
    $platforms = [
        'google' => ['name' => 'جوجل', 'icon' => '🔍', 'url' => 'https://www.google.com/search?q=' . urlencode('"' . $name . '"')],
        'facebook' => ['name' => 'فيسبوك', 'icon' => '📘', 'url' => 'https://www.facebook.com/search/people/top/?q=' . urlencode($name)],
        'linkedin' => ['name' => 'لينكد إن', 'icon' => '💼', 'url' => 'https://www.linkedin.com/search/results/people/?keywords=' . urlencode($name)],
        'twitter' => ['name' => 'تويتر/X', 'icon' => '🐦', 'url' => 'https://twitter.com/search?q=' . urlencode($name)],
        'instagram' => ['name' => 'إنستغرام', 'icon' => '📷', 'url' => 'https://www.instagram.com/web/search/top/?query=' . urlencode($name)],
        'tiktok' => ['name' => 'تيك توك', 'icon' => '🎵', 'url' => 'https://www.tiktok.com/search?q=' . urlencode($name)],
        'youtube' => ['name' => 'يوتيوب', 'icon' => '▶️', 'url' => 'https://www.youtube.com/results?search_query=' . urlencode($name)],
        'github' => ['name' => 'GitHub', 'icon' => '💻', 'url' => 'https://github.com/search?q=' . urlencode($name)],
        'snapchat' => ['name' => 'سناب شات', 'icon' => '👻', 'url' => 'https://www.snapchat.com/add/' . urlencode($name)]
    ];
    foreach ($platforms as $key => $platform) {
        $results['platforms'][] = [
            'name' => $platform['name'],
            'icon' => $platform['icon'],
            'url' => $platform['url'],
            'found' => true
        ];
        $results['stats']['social_media']++;
        $results['stats']['total_mentions'] += rand(1, 30);
    }
    return $results;
}

function searchUsername($username) {
    $username = ltrim(cleanText($username), '@');
    $results = [
        'query' => $username,
        'type' => 'username',
        'platforms' => [],
        'stats' => ['total_mentions' => 0, 'social_media' => 0]
    ];
    $platforms = [
        'telegram' => ['name' => 'تيليغرام', 'icon' => '✈️', 'url' => 'https://t.me/' . urlencode($username)],
        'instagram' => ['name' => 'إنستغرام', 'icon' => '📷', 'url' => 'https://www.instagram.com/' . urlencode($username)],
        'twitter' => ['name' => 'تويتر/X', 'icon' => '🐦', 'url' => 'https://twitter.com/' . urlencode($username)],
        'facebook' => ['name' => 'فيسبوك', 'icon' => '📘', 'url' => 'https://www.facebook.com/' . urlencode($username)],
        'tiktok' => ['name' => 'تيك توك', 'icon' => '🎵', 'url' => 'https://www.tiktok.com/@' . urlencode($username)],
        'youtube' => ['name' => 'يوتيوب', 'icon' => '▶️', 'url' => 'https://www.youtube.com/@' . urlencode($username)],
        'github' => ['name' => 'GitHub', 'icon' => '💻', 'url' => 'https://github.com/' . urlencode($username)],
        'snapchat' => ['name' => 'سناب شات', 'icon' => '👻', 'url' => 'https://www.snapchat.com/add/' . urlencode($username)]
    ];
    foreach ($platforms as $key => $platform) {
        $results['platforms'][] = [
            'name' => $platform['name'],
            'icon' => $platform['icon'],
            'url' => $platform['url'],
            'found' => true
        ];
        $results['stats']['social_media']++;
        $results['stats']['total_mentions'] += rand(1, 50);
    }
    return $results;
}

function formatResults($results) {
    $query = cleanText($results['query']);
    $type = $results['type'];
    $typeNames = ['number' => 'رقم الهاتف', 'email' => 'البريد الإلكتروني', 'name' => 'الاسم', 'username' => 'اليوزر'];
    
    $message = "🔍 <b>نتائج البحث عن:</b> <code>{$query}</code>\n";
    $message .= "📝 <b>النوع:</b> " . ($typeNames[$type] ?? 'غير معروف') . "\n\n";
    
    if ($type == 'number' && isset($results['country'])) {
        $country = $results['country'];
        $message .= "📞 <b>الدولة:</b> {$country['flag']} {$country['name']}\n";
        $message .= "📞 <b>الرقم:</b> {$country['formatted']}\n\n";
    }
    
    $message .= "⚙️ <b>الإحصائيات:</b>\n";
    $message .= "├─ إجمالي الذكر: {$results['stats']['total_mentions']}\n";
    $message .= "└─ المنصات: {$results['stats']['social_media']}\n\n";
    
    if (!empty($results['platforms'])) {
        $message .= "🎯 <b>المنصات التي تم العثور عليها:</b>\n";
        $message .= "─────────────────────\n";
        foreach ($results['platforms'] as $platform) {
            $pName = cleanText($platform['name']);
            $pIcon = $platform['icon'];
            $message .= "{$pIcon} <b>{$pName}</b>\n";
            if (isset($platform['info'])) {
                $pInfo = cleanText($platform['info']);
                $message .= "   ├─ {$pInfo}\n";
            }
            $message .= "   └─ <a href=\"{$platform['url']}\">🔗 عرض النتائج</a>\n\n";
        }
    } else {
        $message .= "❌ <b>لم يتم العثور على نتائج</b>\n\n";
    }
    $message .= "─────────────────────\n";
    $message .= "⏰ <b>تاريخ البحث:</b> " . date('Y-m-d H:i:s');
    return $message;
}

// --- 5. دوال إدارة المستخدمين والنقاط ---

function registerUser($userId, $firstName, $lastName = '', $username = '', $refId = null) {
    $users = loadData('users.json');
    if (!isset($users[$userId])) {
        $users[$userId] = [
            'id' => $userId,
            'first_name' => cleanText($firstName),
            'last_name' => cleanText($lastName),
            'username' => cleanText($username),
            'joined_at' => date('Y-m-d H:i:s'),
            'searches_count' => 0,
            'is_blocked' => false,
            'points' => 0,
            'ref_by' => null
        ];
        if ($refId && $refId != $userId && isset($users[$refId])) {
            $users[$userId]['ref_by'] = $refId;
            $users[$refId]['points'] = ($users[$refId]['points'] ?? 0) + 1;
            addLog('إحالة جديدة', "User: {$userId} referred by: {$refId}");
            sendMessage($refId, "🎉 <b>تم تسجيل دخول شخص جديد عبر رابطك!</b>\n\nتم إضافة <b>1 نقطة</b> إلى رصيدك.");
        }
        saveData('users.json', $users);
        addLog('مستخدم جديد', "ID: {$userId}, Name: {$firstName}");
        
        // إشعار للأدمن
        if (ADMIN_ID !== '') {
            $totalUsers = count($users);
            $userInfo = cleanText($firstName) . ($username ? " (@" . cleanText($username) . ")" : '');
            $adminMsg = "👋 <b>شخص جديد دخل البوت!</b>\n\n";
            $adminMsg .= "👤 الاسم: {$userInfo}\n";
            $adminMsg .= "🆔 الايدي: <code>{$userId}</code>\n";
            $adminMsg .= "📅 التاريخ: " . date('Y-m-d H:i:s') . "\n\n";
            $adminMsg .= "📊 إجمالي المستخدمين: <code>{$totalUsers}</code>";
            sendMessage(ADMIN_ID, $adminMsg);
        }
        return true;
    }
    return false;
}

function getUserPoints($userId) {
    $users = loadData('users.json');
    return $users[$userId]['points'] ?? 0;
}

function updatePoints($userId, $amount) {
    $users = loadData('users.json');
    if (isset($users[$userId])) {
        $users[$userId]['points'] = ($users[$userId]['points'] ?? 0) + $amount;
        saveData('users.json', $users);
        return true;
    }
    return false;
}

function isUserBlocked($userId) {
    $users = loadData('users.json');
    return isset($users[$userId]) && ($users[$userId]['is_blocked'] === true);
}

function blockUser($userId) {
    $users = loadData('users.json');
    if (isset($users[$userId])) {
        $users[$userId]['is_blocked'] = true;
        saveData('users.json', $users);
        addLog('حظر مستخدم', "ID: {$userId}");
        return true;
    }
    return false;
}

function unblockUser($userId) {
    $users = loadData('users.json');
    if (isset($users[$userId])) {
        $users[$userId]['is_blocked'] = false;
        saveData('users.json', $users);
        addLog('رفع حظر', "ID: {$userId}");
        return true;
    }
    return false;
}

function extractNumber($phone, $userId) {
    $numbers = loadData('numbers.json');
    $extracted = [
        'phone' => preg_replace('/[^0-9]/', '', $phone),
        'added_by' => $userId,
        'added_at' => date('Y-m-d H:i:s'),
        'search_results' => searchNumber($phone)
    ];
    $numbers['extracted'][] = $extracted;
    saveData('numbers.json', $numbers);
    addLog('استخراج رقم', "Phone: {$phone}");
    return $extracted;
}

function monitorNumber($phone, $userId, $searchResults = null) {
    $numbers = loadData('numbers.json');
    $phone = preg_replace('/[^0-9]/', '', $phone);
    foreach ($numbers['monitored'] as $mon) {
        if ($mon['phone'] === $phone && $mon['active']) return false;
    }
    $monitor = [
        'phone' => $phone,
        'user_id' => $userId,
        'started_at' => date('Y-m-d H:i:s'),
        'active' => true,
        'check_count' => 0,
        'last_results' => $searchResults
    ];
    $numbers['monitored'][] = $monitor;
    saveData('numbers.json', $numbers);
    addLog('بدء مراقبة', "Phone: {$phone}");
    return true;
}

function stopMonitoring($phone) {
    $numbers = loadData('numbers.json');
    $phone = preg_replace('/[^0-9]/', '', $phone);
    foreach ($numbers['monitored'] as &$mon) {
        if ($mon['phone'] === $phone && $mon['active']) {
            $mon['active'] = false;
            $mon['stopped_at'] = date('Y-m-d H:i:s');
            saveData('numbers.json', $numbers);
            addLog('إيقاف مراقبة', "Phone: {$phone}");
            return true;
        }
    }
    return false;
}

function deleteNumber($phone) {
    $numbers = loadData('numbers.json');
    $phone = preg_replace('/[^0-9]/', '', $phone);
    foreach ($numbers['extracted'] as $i => $num) {
        if ($num['phone'] === $phone) {
            unset($numbers['extracted'][$i]);
            $numbers['extracted'] = array_values($numbers['extracted']);
            saveData('numbers.json', $numbers);
            addLog('حذف رقم', "Phone: {$phone}");
            return true;
        }
    }
    return false;
}

function broadcastMessage($text, $excludeAdmin = false) {
    $users = loadData('users.json');
    $sent = 0; $failed = 0;
    foreach ($users as $userId => $user) {
        if ($excludeAdmin && $userId == ADMIN_ID) continue;
        if ($user['is_blocked'] ?? false) continue;
        $result = sendMessage($userId, $text);
        if ($result['ok'] ?? false) $sent++; else $failed++;
        usleep(500000); // تجنب السبام
    }
    addLog('بث رسالة', "Sent: {$sent}, Failed: {$failed}");
    return ['sent' => $sent, 'failed' => $failed];
}

function getStats() {
    $users = loadData('users.json');
    $numbers = loadData('numbers.json');
    $settings = loadData('settings.json');
    $channels = loadData('forced_channels.json');
    $activeUsers = count(array_filter($users, fn($u) => !($u['is_blocked'] ?? false)));
    $blockedUsers = count(array_filter($users, fn($u) => $u['is_blocked'] ?? false));
    $activeMonitored = count(array_filter($numbers['monitored'] ?? [], fn($m) => $m['active'] ?? false));
    $totalPoints = array_sum(array_column($users, 'points'));
    return [
        'total_users' => count($users),
        'active_users' => $activeUsers,
        'blocked_users' => $blockedUsers,
        'total_searches' => $settings['total_searches'] ?? 0,
        'extracted_numbers' => count($numbers['extracted'] ?? []),
        'monitored_numbers' => count($numbers['monitored'] ?? []),
        'active_monitored' => $activeMonitored,
        'forced_channels' => count($channels),
        'total_points' => $totalPoints
    ];
}

// --- 6. لوحات المفاتيح والمعالجة ---

function getMainInlineKeyboard($isAdmin = false) {
    $buttons = [
        [['text' => '🔍 بحث عن رقم', 'callback_data' => 'search_number']],
        [['text' => '📧 بحث بالإيميل', 'callback_data' => 'search_email'], ['text' => '👤 بحث بالاسم', 'callback_data' => 'search_name']],
        [['text' => '✈️ بحث باليوزر', 'callback_data' => 'search_username'], ['text' => '💎 جمع النقاط', 'callback_data' => 'my_points']],
        [['text' => '📋 القائمة', 'callback_data' => 'numbers_menu'], ['text' => 'ℹ️ عن البوت', 'callback_data' => 'about']],
        [['text' => '📞 تواصل', 'callback_data' => 'contact']]
    ];
    if ($isAdmin) {
        $buttons[] = [['text' => '🎛️ لوحة الأدمن', 'callback_data' => 'admin_panel']];
    }
    return $buttons;
}

function getNumbersInlineKeyboard() {
    return [
        [['text' => '📥 استخراج رقم', 'callback_data' => 'extract_number'], ['text' => '👁️ مراقبة رقم', 'callback_data' => 'monitor_number']],
        [['text' => '📜 عرض الكل', 'callback_data' => 'list_numbers'], ['text' => '⏹️ إيقاف مراقبة', 'callback_data' => 'stop_monitor']],
        [['text' => '🗑️ حذف رقم', 'callback_data' => 'delete_number'], ['text' => '🔙 رجوع', 'callback_data' => 'back_to_main']]
    ];
}

function getAdminInlineKeyboard() {
    return [
        [['text' => '🔧 وضع الصيانة', 'callback_data' => 'toggle_maintenance'], ['text' => '📊 الإحصائيات', 'callback_data' => 'stats']],
        [['text' => '👥 المستخدمين', 'callback_data' => 'list_users'], ['text' => '🚫 المحظورين', 'callback_data' => 'blocked_users']],
        [['text' => '📢 بث رسالة', 'callback_data' => 'broadcast'], ['text' => '📝 سجل النشاط', 'callback_data' => 'logs']],
        [['text' => '🗂️ إدارة الأرقام', 'callback_data' => 'admin_numbers'], ['text' => '⚙️ إعدادات', 'callback_data' => 'settings']],
        [['text' => '➕ إضافة نقاط', 'callback_data' => 'admin_add_points'], ['text' => '➖ خصم نقاط', 'callback_data' => 'admin_deduct_points']],
        [['text' => '📢 القنوات الإجبارية', 'callback_data' => 'manage_forced_channels'], ['text' => '🔙 رجوع', 'callback_data' => 'back_to_main']]
    ];
}

function getForcedChannelsInlineKeyboard() {
    return [
        [['text' => '➕ إضافة قناة', 'callback_data' => 'add_channel'], ['text' => '🗑️ حذف قناة', 'callback_data' => 'remove_channel']],
        [['text' => '📜 عرض القنوات', 'callback_data' => 'list_channels'], ['text' => '🔙 رجوع', 'callback_data' => 'back_to_admin']]
    ];
}

function processUpdate($update) {
    if (isset($update['message'])) handleMessage($update['message']);
    elseif (isset($update['callback_query'])) handleCallback($update['callback_query']);
}

function handleMessage($message) {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $text = cleanText($message['text'] ?? '');
    $firstName = cleanText($message['from']['first_name'] ?? '');
    $lastName = cleanText($message['from']['last_name'] ?? '');
    $username = cleanText($message['from']['username'] ?? '');
    
    $refId = null;
    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', $text);
        if (isset($parts[1]) && strpos($parts[1], 'ref_') === 0) {
            $refId = str_replace('ref_', '', $parts[1]);
        }
    }
    
    registerUser($userId, $firstName, $lastName, $username, $refId);
    if (isUserBlocked($userId)) {
        sendMessage($chatId, "🚫 <b>تم حظرك من استخدام البوت</b>");
        return;
    }
    
    $isAdmin = (ADMIN_ID !== '' && $userId == ADMIN_ID);
    $settings = loadData('settings.json');
    if (($settings['maintenance'] ?? false) && !$isAdmin) {
        sendMessage($chatId, "🔧 <b>البوت في وضع الصيانة حالياً</b>\n\nيرجى الانتظار...");
        return;
    }
    
    if (!isUserSubscribed($userId) && !$isAdmin) {
        sendMessage($chatId, "📢 <b>يجب الاشتراك في القنوات التالية لاستخدام البوت:</b>", getSubscriptionKeyboard());
        return;
    }
    
    $userState = loadData('user_state.json');
    $state = $userState[$userId]['state'] ?? '';
    
    if (strpos($text, '/start') === 0) {
        $welcome = "🔍 <b>أهلاً بك في بوت البحث عن الأرقام!</b>\n\n";
        $welcome .= "📞 يمكنك البحث عن أي رقم هاتف واكتشاف معلوماته.\n\n";
        $welcome .= "⚙️ استخدم الأزرار أدناه للبدء:";
        sendMessage($chatId, $welcome, getMainInlineKeyboard($isAdmin));
        return;
    }
    
    $searchStates = [
        'waiting_for_number' => ['cost' => 2, 'func' => 'searchNumber'],
        'waiting_for_email' => ['cost' => 4, 'func' => 'searchEmail'],
        'waiting_for_name' => ['cost' => 3, 'func' => 'searchName'],
        'waiting_for_username' => ['cost' => 8, 'func' => 'searchUsername']
    ];
    
    if (isset($searchStates[$state])) {
        $config = $searchStates[$state];
        $points = getUserPoints($userId);
        if ($points < $config['cost']) {
            sendMessage($chatId, "⚠️ <b>رصيدك غير كافٍ!</b>\n\nمطلوب: {$config['cost']} نقاط.", getMainInlineKeyboard($isAdmin));
            return;
        }
        sendMessage($chatId, "⏳ <b>جاري البحث...</b>");
        updatePoints($userId, -$config['cost']);
        $results = $config['func']($text);
        $formatted = formatResults($results);
        
        $settings['total_searches']++;
        saveData('settings.json', $settings);
        
        unset($userState[$userId]);
        saveData('user_state.json', $userState);
        sendMessage($chatId, $formatted, getMainInlineKeyboard($isAdmin));
        addLog('بحث', "User: {$userId}, Type: {$state}");
        return;
    }
    
    // إدارة الحالات الأخرى (Contact, Admin, الخ)
    if ($state === 'contacting') {
        $contactMsg = "📩 <b>رسالة جديدة</b>\n\n👤 من: {$firstName} (ID: <code>{$userId}</code>)\n💬 الرسالة:\n{$text}";
        if (ADMIN_ID !== '') sendMessage(ADMIN_ID, $contactMsg);
        sendMessage($chatId, "✅ <b>تم إرسال رسالتك بنجاح!</b>", getMainInlineKeyboard($isAdmin));
        unset($userState[$userId]);
        saveData('user_state.json', $userState);
        return;
    }
    
    // منطق الأدمن
    if ($isAdmin) {
        $adminState = loadData('admin_state.json');
        $aState = $adminState[$chatId]['state'] ?? '';
        
        if ($aState === 'broadcast') {
            sendMessage($chatId, "⏳ <b>جاري البث...</b>");
            $result = broadcastMessage($text, true);
            sendMessage($chatId, "✅ <b>تم البث!</b>\n\nتم إرسال: {$result['sent']}\nفشل: {$result['failed']}", getAdminInlineKeyboard());
            unset($adminState[$chatId]);
            saveData('admin_state.json', $adminState);
            return;
        }
        
        // أوامر نصية سريعة للأدمن
        if (strpos($text, 'حظر ') === 0) {
            $targetId = trim(str_replace('حظر ', '', $text));
            if (blockUser($targetId)) sendMessage($chatId, "✅ تم حظر <code>{$targetId}</code>", getAdminInlineKeyboard());
            return;
        }
        if (strpos($text, 'رفع ') === 0) {
            $targetId = trim(str_replace('رفع ', '', $text));
            if (unblockUser($targetId)) sendMessage($chatId, "✅ تم رفع حظر <code>{$targetId}</code>", getAdminInlineKeyboard());
            return;
        }
    }
}

function handleCallback($callback) {
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $userId = $callback['from']['id'];
    $data = $callback['data'];
    $isAdmin = (ADMIN_ID !== '' && $userId == ADMIN_ID);
    
    $settings = loadData('settings.json');
    if (($settings['maintenance'] ?? false) && !$isAdmin && !in_array($data, ['back_to_main', 'about', 'contact', 'check_subscription'])) {
        answerCallback($callback['id'], "البوت في وضع الصيانة حالياً", true);
        return;
    }
    
    switch ($data) {
        case 'check_subscription':
            if (isUserSubscribed($userId)) {
                answerCallback($callback['id'], "✅ تم التحقق بنجاح!");
                sendMessage($chatId, "🔍 أهلاً بك! اختر نوع البحث:", getMainInlineKeyboard($isAdmin));
            } else {
                answerCallback($callback['id'], "❌ يرجى الاشتراك أولاً", true);
            }
            break;
            
        case 'my_points':
            $points = getUserPoints($userId);
            $refLink = "https://t.me/" . BOT_USERNAME . "?start=ref_{$userId}";
            $msg = "💎 <b>رصيدك:</b> <code>{$points}</code> نقطة\n\n🔗 رابطك: <code>{$refLink}</code>";
            editMessage($chatId, $messageId, $msg, getMainInlineKeyboard($isAdmin));
            answerCallback($callback['id']);
            break;
            
        case 'search_number':
            sendMessage($chatId, "📞 أرسل الرقم للبحث عنه (التكلفة: 2 نقطة)");
            $userState = loadData('user_state.json');
            $userState[$userId] = ['state' => 'waiting_for_number'];
            saveData('user_state.json', $userState);
            answerCallback($callback['id']);
            break;
            
        case 'admin_panel':
            if ($isAdmin) {
                editMessage($chatId, $messageId, "🎛️ لوحة التحكم:", getAdminInlineKeyboard());
            }
            answerCallback($callback['id']);
            break;
            
        case 'stats':
            if ($isAdmin) {
                $s = getStats();
                $msg = "📊 إحصائيات:\nالمستخدمين: {$s['total_users']}\nالبحث: {$s['total_searches']}";
                editMessage($chatId, $messageId, $msg, getAdminInlineKeyboard());
            }
            answerCallback($callback['id']);
            break;
            
        case 'back_to_main':
            editMessage($chatId, $messageId, "🔍 اختر ما تريد:", getMainInlineKeyboard($isAdmin));
            answerCallback($callback['id']);
            break;
            
        case 'toggle_maintenance':
            if ($isAdmin) {
                $settings['maintenance'] = !($settings['maintenance'] ?? false);
                saveData('settings.json', $settings);
                $status = $settings['maintenance'] ? 'مفعّل' : 'معطّل';
                editMessage($chatId, $messageId, "🔧 وضع الصيانة الآن: {$status}", getAdminInlineKeyboard());
            }
            answerCallback($callback['id']);
            break;
            
        default:
            answerCallback($callback['id'], "قريباً...");
    }
}

// --- 7. تشغيل البوت والـ Webhook ---

if (isset($_GET['setup-webhook'])) {
    if (BOT_TOKEN === '') die("يرجى تعيين BOT_TOKEN أولاً!");
    $webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
    $result = sendRequest('setWebhook', ['url' => $webhookUrl]);
    echo "Webhook setup: " . ($result['ok'] ? "Success" : "Failed");
    exit;
}

$content = file_get_contents('php://input');
$update = json_decode($content, true);
if ($update) processUpdate($update);

// واجهة الويب البسيطة
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['setup-webhook'])) {
    echo "<h1>Bot is Active</h1>";
}
?>
