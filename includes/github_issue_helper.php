<?php
/**
 * includes/github_issue_helper.php
 *
 * Bridge: create a GitHub Issue for a Sentry event so that Claude Code can
 * pick it up via @claude mention + label.
 *
 * Token: a fine-grained Personal Access Token with "Issues: read/write" scope
 * on the target repo, stored in config/secrets.php as GITHUB_TOKEN.
 *
 * Target repo: GITHUB_REPO in the format "owner/name".
 *
 * Idempotency: callers pass the Sentry event row id; we only create an issue
 * once per sentry_id (checked via the github_issue_url column).
 */
declare(strict_types=1);

/**
 * Create a GitHub Issue from a Sentry event row.
 *
 * @param array $event   Row from sys_sentry_events (must include id, sentry_id, title, ...)
 * @param array $secrets Full secrets array (GITHUB_TOKEN, GITHUB_REPO)
 * @return array{ok: bool, message: string, url?: string, number?: int, http_status?: int}
 */
function github_issue_create_from_sentry(array $event, array $secrets): array
{
    $token = trim((string)($secrets['GITHUB_TOKEN'] ?? ''));
    $repo  = trim((string)($secrets['GITHUB_REPO']  ?? ''));

    if ($token === '') return ['ok' => false, 'message' => 'GITHUB_TOKEN not configured'];
    if ($repo  === '') return ['ok' => false, 'message' => 'GITHUB_REPO not configured'];
    if (!preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        return ['ok' => false, 'message' => 'GITHUB_REPO must be in "owner/name" format'];
    }

    $sentryId    = (string)($event['sentry_id']   ?? '');
    $title       = (string)($event['title']       ?? '(no title)');
    $level       = (string)($event['level']       ?? '');
    $culprit     = (string)($event['culprit']     ?? '');
    $environment = (string)($event['environment'] ?? '');
    $sentryUrl   = (string)($event['url']         ?? '');
    $rawPayload  = (string)($event['raw_payload'] ?? '');
    $receivedAt  = (string)($event['received_at'] ?? date('Y-m-d H:i:s'));

    $shortPayload = $rawPayload !== ''
        ? mb_substr($rawPayload, 0, 4000) . (mb_strlen($rawPayload) > 4000 ? "\n…(truncated)" : '')
        : '(no payload)';

    $issueTitle = mb_substr('[Sentry] ' . $title, 0, 240);

    $body  = "@claude please investigate this Sentry alert.\n\n";
    if ($sentryUrl) $body .= "**Sentry**: [Open in Sentry]($sentryUrl)\n";
    $body .= "**Level**: `$level`\n";
    $body .= "**Environment**: `$environment`\n";
    $body .= "**Culprit**: `" . str_replace('`', '', $culprit) . "`\n";
    $body .= "**Received**: $receivedAt\n";
    $body .= "**Sentry ID**: `$sentryId`\n\n";
    $body .= "### Title\n$title\n\n";
    $body .= "<details><summary>Raw Sentry payload</summary>\n\n```json\n$shortPayload\n```\n\n</details>\n\n";
    $body .= "---\n*Auto-created by `api/sentry_webhook.php`*";

    $labels = ['sentry', 'claude', 'bug'];
    if ($level !== '' && in_array($level, ['error','warning','info','debug','fatal'], true)) {
        $labels[] = 'sentry:' . $level;
    }

    $payload = ['title' => $issueTitle, 'body' => $body, 'labels' => $labels];

    $ch = curl_init("https://api.github.com/repos/{$repo}/issues");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: RSU-Medical-Clinic-Services',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'message' => 'cURL error: ' . $curlErr, 'http_status' => $status];
    }

    $decoded = is_string($response) ? json_decode($response, true) : null;

    if ($status !== 201) {
        $apiMsg = is_array($decoded) ? (string)($decoded['message'] ?? 'unknown error') : 'unknown error';
        return ['ok' => false, 'message' => "GitHub API $status: $apiMsg", 'http_status' => $status];
    }

    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'GitHub returned non-JSON response', 'http_status' => $status];
    }

    return [
        'ok'          => true,
        'message'     => 'Issue created',
        'url'         => (string)($decoded['html_url'] ?? ''),
        'number'      => (int)($decoded['number'] ?? 0),
        'http_status' => $status,
    ];
}
