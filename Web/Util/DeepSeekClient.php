<?php

declare(strict_types=1);

namespace openvk\Web\Util;

final class DeepSeekClient
{
    public function decide(array $messages): array
    {
        $conf = OPENVK_ROOT_CONF["openvk"]["credentials"]["deepseek"] ?? null;
        if (!$conf || !($conf["enable"] ?? false)) {
            throw new \RuntimeException("DeepSeek is disabled in config");
        }

        $apiKey  = trim((string) ($conf["apiKey"] ?? ""));
        $baseUrl = rtrim((string) ($conf["baseUrl"] ?? "https://api.deepseek.com"), "/");
        $model   = trim((string) ($conf["model"] ?? "deepseek-chat"));

        if ($apiKey === "") {
            throw new \RuntimeException("DeepSeek API key is missing");
        }

        $payload = json_encode([
            "model"       => $model,
            "temperature" => 0.7,
            "messages"    => $messages,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $opts = [
            "http" => [
                "method"        => "POST",
                "header"        => implode("\r\n", [
                    "Content-Type: application/json",
                    "Authorization: Bearer " . $apiKey,
                ]),
                "content"       => $payload,
                "timeout"       => 45,
                "ignore_errors" => true,
            ],
        ];

        $response = @file_get_contents($baseUrl . "/chat/completions", false, stream_context_create($opts));
        if ($response === false || trim($response) === "") {
            throw new \RuntimeException("DeepSeek request failed");
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new \RuntimeException("DeepSeek returned invalid JSON");
        }

        if (isset($json["error"])) {
            $message = is_array($json["error"]) ? ($json["error"]["message"] ?? "Unknown API error") : (string) $json["error"];
            throw new \RuntimeException("DeepSeek API error: " . $message);
        }

        $content = trim((string) ($json["choices"][0]["message"]["content"] ?? ""));
        if ($content === "") {
            throw new \RuntimeException("DeepSeek returned an empty response");
        }

        return $this->extractJson($content);
    }

    private function extractJson(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, "```")) {
            $content = preg_replace('~^```(?:json)?\s*|\s*```$~u', "", $content) ?? $content;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('~\{.*\}~su', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException("DeepSeek did not return valid action JSON");
    }
}
