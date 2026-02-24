<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiControllerBase;

class ImportController extends ApiControllerBase
{
    public function parseAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        $link = $this->extractLink();

        if (empty($link)) {
            return ['status' => 'error', 'message' => 'No VLESS link provided'];
        }

        if (strlen($link) > 2048) {
            return ['status' => 'error', 'message' => 'Link too long'];
        }

        $data = $this->parseVless($link);
        if (isset($data['error'])) {
            return ['status' => 'error', 'message' => $data['error']];
        }

        $data['status'] = 'ok';
        return $data;
    }

    /**
     * Извлекаем ссылку из запроса всеми возможными способами.
     * Браузер может прислать: JSON body, form POST, или base64-encoded.
     */
    private function extractLink(): string
    {
        // Способ 1: JSON body ($.ajax contentType: application/json)
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $json = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // base64-encoded link (самый надёжный способ)
                if (!empty($json['link_b64'])) {
                    $decoded = base64_decode($json['link_b64'], true);
                    if ($decoded !== false) {
                        return trim($decoded);
                    }
                }
                // обычный JSON link
                if (!empty($json['link'])) {
                    return trim($json['link']);
                }
            }
        }

        // Способ 2: POST параметр link_b64 (base64, & не страшен)
        $b64 = $this->request->getPost('link_b64', 'string', '');
        if (!empty($b64)) {
            $decoded = base64_decode($b64, true);
            if ($decoded !== false) {
                return trim($decoded);
            }
        }

        // Способ 3: обычный POST link (работает только без & в строке)
        $link = $this->request->getPost('link', 'string', '');
        if (!empty($link)) {
            return trim($link);
        }

        return '';
    }

    private function parseVless(string $link): array
    {
        // Убираем лишние пробелы и кавычки
        $link = trim($link, " \t\n\r\0\x0B\"'");

        if (strpos($link, 'vless://') !== 0) {
            return ['error' => 'Link must start with vless://'];
        }

        // Убираем схему
        $rest = substr($link, 8); // после "vless://"

        // Отделяем #name в конце
        $name = '';
        if (($hashPos = strrpos($rest, '#')) !== false) {
            $name = urldecode(substr($rest, $hashPos + 1));
            $rest = substr($rest, 0, $hashPos);
        }

        // Отделяем ?query
        $query = '';
        if (($qPos = strpos($rest, '?')) !== false) {
            $query = substr($rest, $qPos + 1);
            $rest  = substr($rest, 0, $qPos);
        }

        // Отделяем UUID@host:port
        // UUID — всё до последнего @ (на случай если @ есть в UUID — маловероятно, но надёжно)
        $atPos = strrpos($rest, '@');
        if ($atPos === false) {
            return ['error' => 'Missing @ separator between UUID and host'];
        }

        $uuid    = substr($rest, 0, $atPos);
        $hostport = substr($rest, $atPos + 1);

        // Разбираем host:port (с учётом IPv6 [::1]:port)
        if (substr($hostport, 0, 1) === '[') {
            // IPv6
            $closeBracket = strpos($hostport, ']');
            if ($closeBracket === false) {
                return ['error' => 'Invalid IPv6 address format'];
            }
            $host = substr($hostport, 1, $closeBracket - 1);
            $portStr = ltrim(substr($hostport, $closeBracket + 1), ':');
        } else {
            $lastColon = strrpos($hostport, ':');
            if ($lastColon === false) {
                return ['error' => 'Missing port in host:port'];
            }
            $host    = substr($hostport, 0, $lastColon);
            $portStr = substr($hostport, $lastColon + 1);
        }

        $port = (int)$portStr;
        if ($port <= 0 || $port > 65535) {
            return ['error' => 'Invalid port: ' . $portStr];
        }

        if (empty($uuid)) {
            return ['error' => 'UUID is empty'];
        }
        if (empty($host)) {
            return ['error' => 'Host is empty'];
        }

        // Парсим query string
        parse_str($query, $params);

        $flow = $params['flow'] ?? '';
        if (empty($flow)) {
            $flow = 'xtls-rprx-vision';
        }

        $fp = $params['fp'] ?? '';
        if (empty($fp)) {
            $fp = 'chrome';
        }

        return [
            'uuid'     => $uuid,
            'host'     => $host,
            'port'     => $port,
            'flow'     => $flow,
            'sni'      => $params['sni']      ?? '',
            'pbk'      => $params['pbk']      ?? '',
            'sid'      => $params['sid']      ?? '',
            'fp'       => $fp,
            'type'     => $params['type']     ?? 'tcp',
            'security' => $params['security'] ?? 'reality',
            'name'     => $name,
        ];
    }
}
