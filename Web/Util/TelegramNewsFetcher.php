<?php

declare(strict_types=1);

namespace openvk\Web\Util;

final class TelegramNewsFetcher
{
    public function fetchChannel(string $handle, int $limit = 20): array
    {
        $handle = ltrim(trim($handle), "@");
        $url    = "https://t.me/s/" . rawurlencode($handle);
        $opts   = [
            "http" => [
                "method"  => "GET",
                "timeout" => 15,
                "header"  => implode("\r\n", [
                    "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36",
                    "Accept-Language: ru,en;q=0.9",
                ]),
            ],
        ];

        $html = @file_get_contents($url, false, stream_context_create($opts));
        if ($html === false || trim($html) === "") {
            throw new \RuntimeException("Unable to fetch Telegram channel page for @" . $handle);
        }

        $prevState = libxml_use_internal_errors(true);
        $dom       = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prevState);

        $xpath = new \DOMXPath($dom);
        $items = [];

        foreach ($xpath->query("//div[contains(@class, 'tgme_widget_message')][@data-post]") as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $dataPost = $node->getAttribute("data-post");
            if (!preg_match('~^[^/]+/(\d+)$~', $dataPost, $matches)) {
                continue;
            }

            $externalId = (int) $matches[1];
            $authorNode = $xpath->query(".//a[contains(@class, 'tgme_widget_message_owner_name')]/span", $node)->item(0);
            $avatarNode = $xpath->query(".//div[contains(@class, 'tgme_widget_message_user')]//img", $node)->item(0);
            $timeNode   = $xpath->query(".//a[contains(@class, 'tgme_widget_message_date')]/time", $node)->item(0);
            $linkNode   = $xpath->query(".//a[contains(@class, 'tgme_widget_message_date')]", $node)->item(0);
            $textNode   = $xpath->query(".//div[contains(@class, 'tgme_widget_message_text')]", $node)->item(0);
            $photoNode  = $xpath->query(".//a[contains(@class, 'tgme_widget_message_photo_wrap')]", $node)->item(0);
            $videoNode  = $xpath->query(".//i[contains(@class, 'tgme_widget_message_video_thumb')]", $node)->item(0);

            $publishedAt = $timeNode instanceof \DOMElement ? $timeNode->getAttribute("datetime") : null;
            if (!$publishedAt) {
                continue;
            }

            $text = $textNode instanceof \DOMElement ? $this->extractText($textNode) : "";
            $text = preg_replace('~(?:\R)?@' . preg_quote($handle, "~") . '$~ui', "", $text ?? "");
            $text = trim((string) $text);

            $imageUrl = null;
            if ($photoNode instanceof \DOMElement) {
                $imageUrl = $this->extractBackgroundImage($photoNode->getAttribute("style"));
            } elseif ($videoNode instanceof \DOMElement) {
                $imageUrl = $this->extractBackgroundImage($videoNode->getAttribute("style"));
            }

            $items[] = [
                "external_id"  => $externalId,
                "text"         => $text,
                "image_url"    => $imageUrl,
                "original_url" => $linkNode instanceof \DOMElement ? $linkNode->getAttribute("href") : "https://t.me/" . $handle . "/" . $externalId,
                "published_at" => date("Y-m-d H:i:s", strtotime($publishedAt)),
                "source_title" => $authorNode ? trim($authorNode->textContent) : $handle,
                "avatar_url"   => $avatarNode instanceof \DOMElement ? $avatarNode->getAttribute("src") : null,
                "handle"       => $handle,
            ];

            if (sizeof($items) >= $limit) {
                break;
            }
        }

        return [
            "title"      => $items[0]["source_title"] ?? $handle,
            "avatar_url" => $items[0]["avatar_url"] ?? null,
            "items"      => $items,
        ];
    }

    private function extractBackgroundImage(string $style): ?string
    {
        if (preg_match("~background-image:url\\('([^']+)'\\)~", $style, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, "UTF-8");
        }

        return null;
    }

    private function extractText(\DOMElement $node): string
    {
        $html = "";
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? "";
        }

        $html = preg_replace("~<br\\s*/?>~i", "\n", $html ?? "");
        $html = preg_replace("~</p>~i", "\n\n", $html ?? "");
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, "UTF-8");
        $text = str_replace("\xc2\xa0", " ", $text);
        $text = preg_replace("~[ \t]+\n~", "\n", $text);
        $text = preg_replace("~\n{3,}~", "\n\n", $text);

        return trim((string) $text);
    }
}
