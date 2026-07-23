<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// === VALIDATE INPUT URL ===
if (!isset($_GET['url']) || empty($_GET['url'])) {
    echo json_encode(["error" => "Missing 'url' GET parameter"]);
    exit;
}

$targetInstaURL = trim($_GET['url']);

// Validate it's an Instagram URL
if (!preg_match('#^https?://(www\.)?instagram\.com/.*#i', $targetInstaURL)) {
    echo json_encode(["error" => "URL must be a valid Instagram link"]);
    exit;
}

// Optional: allow only certain Instagram URL types
$validPatterns = [
    '#^https?://(www\.)?instagram\.com/[^/]+/?$#i',                          // profile
    '#^https?://(www\.)?instagram\.com/p/[^/]+/?$#i',                        // post
    '#^https?://(www\.)?instagram\.com/reel/[^/]+/?$#i',                     // reel
    '#^https?://(www\.)?instagram\.com/stories/[^/]+/[^/]+/?$#i',            // story
];

$validType = false;
foreach ($validPatterns as $pattern) {
    if (preg_match($pattern, $targetInstaURL)) {
        $validType = true;
        break;
    }
}

if (!$validType) {
    echo json_encode(["error" => "Only profile, post, reel, or story URLs are supported."]);
    exit;
}

// === CONFIG ===
$delay_mode = "random"; // "fixed" or "random"
$fixed_delay = 1.5;
$delay_min = 1;
$delay_max = 1;

// === Delay helper ===
function delay_request($mode, $fixed, $min, $max) {
    $sec = ($mode === "random") ? rand($min * 10, $max * 10) / 10 : $fixed;
    usleep($sec * 1000000);
}

function rebuild_and_parse_media($html) {
    try {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $result = [
            "results_images" => 0,
            "results_videos" => 0,
            "images" => [],
            "videos" => []
        ];

        foreach ($xpath->query('//ul[@class="download-box"]/li') as $li) {
            // Thumbnail img element
            $thumbNode = $xpath->query('.//div[contains(@class,"download-items__thumb")]//img', $li)->item(0);
            $thumb = null;
            if ($thumbNode) {
                $src = $thumbNode->getAttribute("src");
                $dataSrc = $thumbNode->getAttribute("data-src");
                // If src is missing or is loader gif, use data-src if available
                if (!$src || $src === '/imgs/loader.gif') {
                    $thumb = $dataSrc ?: $src;
                } else {
                    $thumb = $src;
                }
            }

            // Icon node inside thumbnail
            $iconNode = $xpath->query('.//div[contains(@class,"download-items__thumb")]//i', $li)->item(0);
            $iconClass = $iconNode ? $iconNode->getAttribute("class") : '';
            $isImage = false;
            $isVideo = false;

            if ($iconNode) {
                $isImage = (strpos($iconClass, 'icon-dlimage') !== false || $iconNode->hasAttribute("icon-dlimage"));
                $isVideo = (strpos($iconClass, 'icon-dlvideo') !== false || $iconNode->hasAttribute("icon-dlvideo"));
            }

            // Find <a> elements inside .download-items__btn divs (may be multiple)
            $btnNodes = $xpath->query('.//div[contains(@class,"download-items__btn")]//a', $li);
            
            // For videos, we want to find the <a> with attribute video="" or the one containing "Download Video" text
            $videoHref = null;
            foreach ($btnNodes as $a) {
                if ($a->hasAttribute('video')) {
                    $videoHref = $a->getAttribute('href');
                    $isVideo = true;
                    break;
                }
                $textContent = strtolower(trim($a->textContent));
                if (str_contains($textContent, 'download video')) {
                    $videoHref = $a->getAttribute('href');
                    $isVideo = true;
                    break;
                }
            }

            // Also if not found above, but icon says video, just try first <a>
            if ($isVideo && !$videoHref && $btnNodes->length > 0) {
                $videoHref = $btnNodes->item(0)->getAttribute('href');
            }

            // Resolutions from select options
            $resolutions = [];
            foreach ($xpath->query('.//div[contains(@class,"photo-option")]//select/option', $li) as $option) {
                $resolutions[] = [trim($option->nodeValue) => $option->getAttribute("value")];
            }

            if ($isImage) {
                $result["results_images"]++;
                $result["images"][] = [
                    "thumb_url" => $thumb,
                    "resolutions_count" => count($resolutions),
                    "resolution" => $resolutions
                ];
            } elseif ($isVideo) {
                $result["results_videos"]++;
                $result["videos"][] = [
                    "thumb_url" => $thumb,
                    "video_src" => $videoHref,
                    "resolutions_count" => count($resolutions),
                    "resolution" => $resolutions
                ];
            }
        }

        return $result;
    } catch (Exception $e) {
        return ["error" => "Parse failed: " . $e->getMessage()];
    }
}

// === STEP 1: Fetch HTML from saveinsta.to ===
$url = "https://saveinsta.to/en/highlights";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_HTTPHEADER => [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
        "Accept-Language: en-US,en;q=0.9",
        "Cache-Control: max-age=0",
        "Referer: https://www.google.com/",
        "Sec-CH-UA: \"Not)A;Brand\";v=\"8\", \"Chromium\";v=\"138\", \"Brave\";v=\"138\"",
        "Sec-CH-UA-Mobile: ?0",
        "Sec-CH-UA-Platform: \"Windows\"",
        "Sec-Fetch-Dest: document",
        "Sec-Fetch-Mode: navigate",
        "Sec-Fetch-Site: cross-site",
        "Sec-Fetch-User: ?1",
        "Sec-GPC: 1",
        "Upgrade-Insecure-Requests: 1",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36"
    ]
]);
$response = curl_exec($ch);
curl_close($ch);

preg_match('/<script[^>]*>var\s+k_url_search="[^"]+"(.*?)<\/script>/s', $response, $matches);
if (!isset($matches[1])) {
    echo json_encode(["error" => "JS token block not found"]);
    exit;
}
$scriptBlock = $matches[1];
function extractJsVar($name, $source) {
    if (preg_match('/' . preg_quote($name, '/') . '\s*=\s*"([^"]+)"/', $source, $match)) {
        return $match[1];
    }
    return null;
}
$k_prefix_name = extractJsVar("k_prefix_name", $scriptBlock);
$k_exp = extractJsVar("k_exp", $scriptBlock);
$k_token = extractJsVar("k_token", $scriptBlock);

// === STEP 2: Delay
delay_request($delay_mode, $fixed_delay, $delay_min, $delay_max);

// === STEP 3: Get CF token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "https://saveinsta.to/api/userverify",
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_POSTFIELDS => http_build_query(["url" => $targetInstaURL]),
    CURLOPT_HTTPHEADER => [
        "Accept: */*",
        "Accept-Language: en-US,en;q=0.9",
        "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
        "Origin: https://saveinsta.to",
        "Referer: https://saveinsta.to/en/video",
        "Sec-CH-UA: \"Not)A;Brand\";v=\"8\", \"Chromium\";v=\"138\", \"Brave\";v=\"138\"",
        "Sec-CH-UA-Mobile: ?0",
        "Sec-CH-UA-Platform: \"Windows\"",
        "Sec-Fetch-Dest: empty",
        "Sec-Fetch-Mode: cors",
        "Sec-Fetch-Site: same-origin",
        "Sec-GPC: 1",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36",
        "X-Requested-With: XMLHttpRequest"
    ]
]);
$cftokenResponse = curl_exec($ch);
curl_close($ch);
$cftokenData = json_decode($cftokenResponse, true);
if (!$cftokenData || !isset($cftokenData["token"])) {
    echo json_encode(["error" => "CF token not returned"]);
    exit;
}
$cftoken = $cftokenData["token"];

// === STEP 4: Delay
delay_request($delay_mode, $fixed_delay, $delay_min, $delay_max);

// === STEP 5: Request final content
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "https://saveinsta.to/api/ajaxSearch",
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_POSTFIELDS => http_build_query([
        "k_exp" => $k_exp,
        "k_token" => $k_token,
        "q" => $targetInstaURL,
        "t" => "media",
        "lang" => "en",
        "v" => "v2",
        "cftoken" => $cftoken
    ]),
    CURLOPT_HTTPHEADER => [
        "Accept: */*",
        "Accept-Language: en-US,en;q=0.9",
        "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
        "Origin: https://saveinsta.to",
        "Referer: https://saveinsta.to/en/highlights",
        "Sec-CH-UA: \"Not)A;Brand\";v=\"8\", \"Chromium\";v=\"138\", \"Brave\";v=\"138\"",
        "Sec-CH-UA-Mobile: ?0",
        "Sec-CH-UA-Platform: \"Windows\"",
        "Sec-Fetch-Dest: empty",
        "Sec-Fetch-Mode: cors",
        "Sec-Fetch-Site: same-origin",
        "Sec-GPC: 1",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36",
        "X-Requested-With: XMLHttpRequest"
    ]
]);
$finalResponse = curl_exec($ch);
curl_close($ch);
$finalData = json_decode($finalResponse, true);

// === STEP 6: Parse final HTML
if (isset($finalData["status"]) && $finalData["status"] === "ok" && isset($finalData["data"])) {
    $parsedMedia = rebuild_and_parse_media($finalData["data"]);
    echo json_encode(["success" => true, "media" => $parsedMedia], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo json_encode(["error" => "Invalid response", "raw" => $finalData]);
}
