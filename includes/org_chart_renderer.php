<?php
// includes/org_chart_renderer.php — shared org-chart rendering helpers
// Used by user/org_chart.php (public view) and the admin preview pane in
// portal/_partials/clinic_data/org_chart.php.
declare(strict_types=1);

if (!function_exists('ocrEsc')) {
    function ocrEsc(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('ocrPhotoOrInitial')) {
    function ocrPhotoOrInitial(array $m, string $size = 'lg'): string {
        if (!empty($m['photo_url'])) {
            $cls = $size === 'lg' ? 'ocp-photo' : 'ocs-photo';
            return '<img src="' . ocrEsc($m['photo_url']) . '" alt="" class="' . $cls . '" loading="lazy">';
        }
        $initial = mb_substr(trim((string)$m['full_name']) ?: '?', 0, 1, 'UTF-8');
        $bg = $size === 'lg'
            ? 'background:linear-gradient(135deg,#34d399,#059669);'
            : 'background:linear-gradient(160deg,#34d399,#10b981);';
        $cls = $size === 'lg'
            ? 'ocp-photo flex items-center justify-center text-white text-5xl font-black'
            : 'ocs-photo flex items-center justify-center text-white text-3xl font-black';
        return '<div class="' . $cls . '" style="' . $bg . '">' . ocrEsc($initial) . '</div>';
    }
}

if (!function_exists('ocrResponsibilitiesHtml')) {
    function ocrResponsibilitiesHtml(string $resp): string {
        $lines = array_filter(array_map('trim', preg_split('/\r?\n|•/u', $resp)));
        if (empty($lines)) return '';
        $out = '';
        foreach ($lines as $line) {
            $out .= '<div>• ' . ocrEsc($line) . '</div>';
        }
        return $out;
    }
}

if (!function_exists('ocrRenderPremiumCard')) {
    function ocrRenderPremiumCard(array $m, bool $isMe, bool $inChain): string {
        $classes = ['org-card-premium'];
        if ($isMe) $classes[] = 'org-card-me';
        if ($inChain) $classes[] = 'org-card-in-chain';
        $cls = implode(' ', $classes);

        $name = trim(($m['prefix'] ?? '') . ' ' . $m['full_name']);
        $resp = !empty($m['responsibilities']) ? ocrResponsibilitiesHtml($m['responsibilities']) : '';
        $deptOrLicense = '';
        if (!empty($m['license_no'])) {
            $deptOrLicense = '<div class="ocp-license"><i class="fa-solid fa-id-badge mr-1"></i>ใบอนุญาตฯ: ' . ocrEsc($m['license_no']) . '</div>';
        }
        $body = '';
        if ($resp || !empty($m['department'])) {
            $body .= '<div class="ocp-body">';
            if ($resp) {
                $body .= '<strong>หน้าที่</strong>' . $resp;
            }
            if (!empty($m['department'])) {
                $body .= '<div class="mt-1 pt-1 border-t border-emerald-100"><strong>สังกัด:</strong> ' . ocrEsc($m['department']) . '</div>';
            }
            $body .= '</div>';
        }

        return '<article class="' . $cls . '">
            <svg class="ocp-bg-shapes" viewBox="0 0 288 380" preserveAspectRatio="none" aria-hidden="true">
                <circle cx="55" cy="55" r="55" fill="rgba(255,255,255,0.12)"/>
                <circle cx="240" cy="40" r="35" fill="rgba(251,191,36,0.18)"/>
                <circle cx="260" cy="120" r="20" fill="rgba(255,255,255,0.18)"/>
                <path d="M0 320 Q 144 280 288 320 L 288 380 L 0 380 Z" fill="rgba(255,255,255,0.10)"/>
                <circle cx="40" cy="350" r="12" fill="rgba(251,191,36,0.25)"/>
            </svg>
            <div class="ocp-photo-wrap">' . ocrPhotoOrInitial($m, 'lg') . '</div>
            <div class="ocp-name-pill">' . ocrEsc($name) . '</div>
            ' . $deptOrLicense . $body . '
        </article>';
    }
}

if (!function_exists('ocrRenderSimpleCard')) {
    function ocrRenderSimpleCard(array $m, string $positionTitle, bool $isMe, bool $inChain): string {
        $classes = ['org-card-simple'];
        if ($isMe) $classes[] = 'org-card-me';
        if ($inChain) $classes[] = 'org-card-in-chain';
        $cls = implode(' ', $classes);

        $name = trim(($m['prefix'] ?? '') . ' ' . $m['full_name']);

        return '<article class="' . $cls . '">
            <div class="ocs-bg">
                <svg class="absolute inset-0 w-full h-full pointer-events-none opacity-90" viewBox="0 0 176 160" preserveAspectRatio="none" aria-hidden="true">
                    <circle cx="35" cy="25" r="6" fill="rgba(255,255,255,0.40)"/>
                    <circle cx="155" cy="20" r="4" fill="rgba(251,191,36,0.55)"/>
                    <circle cx="20" cy="135" r="5" fill="rgba(251,191,36,0.40)"/>
                    <path d="M0 0 L 30 0 L 0 30 Z" fill="rgba(255,255,255,0.20)"/>
                </svg>
                <div class="relative">' . ocrPhotoOrInitial($m, 'sm') . '</div>
            </div>
            <div class="ocs-pills">
                <span class="ocs-name-pill">' . ocrEsc($name) . '</span>
                <span class="ocs-role-pill">' . ocrEsc($positionTitle) . '</span>
            </div>
        </article>';
    }
}

if (!function_exists('ocrRenderTree')) {
    function ocrRenderTree(array $childrenByParent, int $parentId, array $membersByPos, array $posById, array $ancestorSet, ?array $myMember, ?int $myPositionId): string {
        $out = '';
        $kids = $childrenByParent[$parentId] ?? [];
        foreach ($kids as $pos) {
            $pid = (int)$pos['id'];
            $posMembers = $membersByPos[$pid] ?? [];
            $hasContent = count($posMembers) > 0;
            $inChain = isset($ancestorSet[$pid]) || ($myPositionId !== null && $myPositionId === $pid);

            if ($hasContent) {
                if (!empty($pos['show_section_header'])) {
                    $out .= '<div class="org-section-title">' . ocrEsc($pos['title']) . '</div>';
                    $out .= '<div class="org-section-underline"></div>';
                }
                $out .= '<div class="org-row">';
                foreach ($posMembers as $m) {
                    $isMe = ($myMember && (int)$m['id'] === (int)$myMember['id']);
                    if ($pos['card_style'] === 'premium') {
                        $out .= ocrRenderPremiumCard($m, $isMe, $inChain && !$isMe);
                    } else {
                        $out .= ocrRenderSimpleCard($m, $pos['title'], $isMe, $inChain && !$isMe);
                    }
                }
                $out .= '</div>';
            }
            $out .= ocrRenderTree($childrenByParent, $pid, $membersByPos, $posById, $ancestorSet, $myMember, $myPositionId);
        }
        return $out;
    }
}

/**
 * Build everything needed to render the chart from the two raw row sets.
 * Returns: ['html' => string, 'totalPositions' => int, 'totalMembers' => int]
 */
if (!function_exists('ocrBuildChart')) {
    function ocrBuildChart(array $positions, array $members, ?int $myUserId = null): array {
        $posById = [];
        $childrenByParent = [];
        foreach ($positions as $p) {
            $posById[(int)$p['id']] = $p;
            $pid = $p['parent_id'] !== null ? (int)$p['parent_id'] : 0;
            $childrenByParent[$pid][] = $p;
        }
        $membersByPos = [];
        foreach ($members as $m) {
            $pid = $m['position_id'] !== null ? (int)$m['position_id'] : 0;
            $membersByPos[$pid][] = $m;
        }

        $myMember = null;
        $myPositionId = null;
        $ancestorPositionIds = [];
        if ($myUserId !== null) {
            foreach ($members as $m) {
                if ($m['user_id'] !== null && (int)$m['user_id'] === $myUserId) {
                    $myMember = $m;
                    $myPositionId = $m['position_id'] !== null ? (int)$m['position_id'] : null;
                    break;
                }
            }
            if ($myPositionId !== null && isset($posById[$myPositionId])) {
                $cur = $posById[$myPositionId];
                $guard = 0;
                while ($cur && $cur['parent_id'] !== null && $guard++ < 50) {
                    $parentId = (int)$cur['parent_id'];
                    if (!isset($posById[$parentId])) break;
                    $ancestorPositionIds[] = $parentId;
                    $cur = $posById[$parentId];
                }
            }
        }
        $ancestorSet = array_flip($ancestorPositionIds);

        $chainPath = [];
        if ($myMember && $myPositionId !== null) {
            $chainPath = array_reverse($ancestorPositionIds);
            $chainPath[] = $myPositionId;
        }

        $html = ocrRenderTree($childrenByParent, 0, $membersByPos, $posById, $ancestorSet, $myMember, $myPositionId);

        return [
            'html'           => $html,
            'totalPositions' => count($positions),
            'totalMembers'   => count($members),
            'myMember'       => $myMember,
            'chainPath'      => $chainPath,
            'posById'        => $posById,
        ];
    }
}
