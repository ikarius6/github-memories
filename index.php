<?php
/*
GitHub Memories SVG Generator
============================

Parameters:
- username      — target account (sanitized)
- type=user|org — org uses REST repo-commits fallback (GraphQL contributionsCollection only exists for users)
- years=N       — years back to sample (clamp 1-10, default 3)
- theme=dark|light
- title=...     — custom title text
- showUsername=0|1 — hide username from the title
- details=0|1   — when 1, render a per-repo breakdown with clickable links
                  to GitHub's commits view filtered by author + that day.
- repoLimit=N   — max repos shown per year when details=1 (clamp 1-20, default 5)

Deploy note: set the GITHUB_TOKEN environment variable on the server.
The script renders an error SVG if the token is missing, and always
escapes dynamic output to prevent SVG injection.

Example URL:
index.php?username=octocat&type=user&years=5&theme=light&title=My%20Memories&showUsername=0&details=1&repoLimit=8
*/

// Cache tuning. CACHE_MAX_FILES caps how many rendered banners we keep on disk
// so the cache dir can't be spammed into filling the disk (each unique set of
// params — including an arbitrary ?title= — produces its own key).
const CACHE_TTL       = 14400; // seconds (4h), also mirrored in Cache-Control.
const CACHE_MAX_FILES = 500;   // hard cap; oldest entries are evicted first.

// 1. Tell the browser/GitHub that this script outputs an SVG image, not HTML
header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=' . CACHE_TTL);

// 2. Read the GitHub token from the environment - NEVER store it in source.
$token = getenv('GITHUB_TOKEN') ?: '';
if ($token === '') {
    renderError('GITHUB_TOKEN env var is not set on the server.');
    return;
}

// 3. Gather URL params with sensible, validated defaults.
$username = preg_replace('/[^A-Za-z0-9\-_]/', '', $_GET['username'] ?? 'octocat');
if ($username === '') {
    $username = 'octocat';
}
$accountType = ($_GET['type'] ?? 'user') === 'org' ? 'org' : 'user';

$yearsCount = (int)($_GET['years'] ?? 3);
if ($yearsCount < 1) $yearsCount = 1;
if ($yearsCount > 10) $yearsCount = 10;

$theme = ($_GET['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';

$rawTitle    = trim($_GET['title'] ?? '');
$customTitle = function_exists('mb_substr')
    ? mb_substr($rawTitle, 0, 100)
    : substr($rawTitle, 0, 100);
$showUsername = filter_var($_GET['showUsername'] ?? '1', FILTER_VALIDATE_BOOLEAN);

$details  = filter_var($_GET['details'] ?? '0', FILTER_VALIDATE_BOOLEAN);
$repoLimit = (int)($_GET['repoLimit'] ?? 5);
if ($repoLimit < 1)  $repoLimit = 1;
if ($repoLimit > 20) $repoLimit = 20;

// 3b. Server-side cache lookup.
// GitHub proxies this image through its Camo service, which has a short fetch
// timeout. A cold render makes `years` sequential GitHub API calls, which can
// blow past that timeout and make the banner appear as a broken image (i.e. a
// bare link). Serving a cached SVG keeps cold Camo fetches well under a second.
// The date is part of the key so the "on this day" data refreshes daily.
$cacheDir  = sys_get_temp_dir() . '/github-memories-cache';
$cacheKey  = md5(implode('|', [
    $username, $accountType, $yearsCount, $theme, $customTitle,
    $showUsername ? '1' : '0', $details ? '1' : '0', $repoLimit,
    date('Y-m-d'),
]));
$cacheFile = $cacheDir . '/' . $cacheKey . '.svg';

$cached = cacheGet($cacheFile, CACHE_TTL);
if ($cached !== null) {
    echo $cached;
    return;
}

// 4. Theme palette
if ($theme === 'light') {
    $palette = [
        'bg'     => '#f6f8fa',
        'stroke' => '#d0d7de',
        'title'  => '#0969da',
        'text'   => '#57606a',
        'count'  => '#1a7f37',
        'repo'   => '#0969da',
        'muted'  => '#8c959f',
    ];
} else {
    $palette = [
        'bg'     => '#0d1117',
        'stroke' => '#30363d',
        'title'  => '#58a6ff',
        'text'   => '#c9d1d9',
        'count'  => '#3fb950',
        'repo'   => '#58a6ff',
        'muted'  => '#8b949e',
    ];
}

// 5. Compute the dates (today, N years back)
$monthDay = date('m-d');
$memories = [];
$todayLabel = date('M j');

for ($i = 1; $i <= $yearsCount; $i++) {
    $targetYear = date('Y') - $i;
    $dateStr = "$targetYear-$monthDay";
    $from = "{$dateStr}T00:00:00Z";
    $to   = "{$dateStr}T23:59:59Z";

    if ($accountType === 'org') {
        $memories[$targetYear] = fetchOrgCommitsOnDay(
            $username, $from, $to, $token, $details ? $repoLimit : 0
        );
    } else {
        $memories[$targetYear] = fetchUserContributionsOnDay(
            $username, $from, $to, $token, $details ? $repoLimit : 0
        );
    }
}

// 6. Build the title text
if ($customTitle !== '') {
    $titleText = $customTitle;
    if ($showUsername) {
        $titleText .= ' - ' . $username;
    }
} else {
    $titleText = 'On This Day (' . $todayLabel . ') - ';
    $titleText .= $showUsername ? $username . "'s Memories" : 'Memories';
}

// 7. Render the SVG
$width = 450;

// Compute dynamic height accounting for repo breakdown rows.
$totalRows = 0;
foreach ($memories as $entry) {
    $totalRows++; // year total line
    if ($details && !empty($entry['repos'])) {
        $totalRows += count($entry['repos']);
    }
    if ($details && !empty($entry['hiddenPrivateCount'])) {
        $totalRows++; // private hidden note
    }
}
$height = 110 + ($totalRows * 24);

$rows = '';
$yOffset = 80;
foreach ($memories as $year => $entry) {
    $count = $entry['total'];
    $label = $count === 1 ? 'contribution' : 'contributions';
    $rows .= sprintf(
        '<text x="25" y="%d" class="text">%d: <tspan class="count">%d %s</tspan></text>',
        $yOffset, htmlspecialchars($year), (int)$count, $label
    );
    $yOffset += 24;

    if ($details && !empty($entry['repos'])) {
        foreach ($entry['repos'] as $repo) {
            $repoDisplay = htmlspecialchars($repo['nameWithOwner']);
            $repoCount   = (int)$repo['count'];
            $href        = htmlspecialchars($repo['url'], ENT_QUOTES);
            $repoLabel   = $repoCount === 1 ? 'commit' : 'commits';
            $rows .= sprintf(
                '<a href="%s" target="_blank"><text x="45" y="%d" class="repo">%s <tspan class="muted">- %d %s</tspan></text></a>',
                $href, $yOffset, $repoDisplay, $repoCount, $repoLabel
            );
            $yOffset += 24;
        }
    }

    // Note any private repos that were counted toward the total but not shown.
    if ($details && !empty($entry['hiddenPrivateCount'])) {
        $hpCount   = (int)$entry['hiddenPrivateCount'];
        $hpCommits = (int)$entry['hiddenPrivateCommits'];
        $rows .= sprintf(
            '<text x="45" y="%d" class="muted">+ %d private repo%s hidden (%d %s counted)</text>',
            $yOffset, $hpCount, $hpCount === 1 ? '' : 's', $hpCommits, $hpCommits === 1 ? 'commit' : 'commits'
        );
        $yOffset += 24;
    }
}

ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<svg width="<?php echo $width; ?>" height="<?php echo $height; ?>" viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" fill="none" xmlns="http://www.w3.org/2000/svg">
    <style>
        .title { font: 600 16px 'Segoe UI', Ubuntu, Sans-Serif; fill: <?php echo $palette['title']; ?>; }
        .text  { font: 400 14px 'Segoe UI', Ubuntu, Sans-Serif; fill: <?php echo $palette['text']; ?>; }
        .count { font: 700 14px 'Segoe UI', Ubuntu, Sans-Serif; fill: <?php echo $palette['count']; ?>; }
        .repo  { font: 600 12px 'Segoe UI', Ubuntu, Sans-Serif; fill: <?php echo $palette['repo']; ?>; text-decoration: underline; }
        .muted { font: 400 12px 'Segoe UI', Ubuntu, Sans-Serif; fill: <?php echo $palette['muted']; ?>; }
        .err   { font: 600 14px 'Segoe UI', Ubuntu, Sans-Serif; fill: #f85149; }
    </style>
    <rect width="<?php echo $width; ?>" height="<?php echo $height; ?>" rx="10" fill="<?php echo $palette['bg']; ?>" stroke="<?php echo $palette['stroke']; ?>"/>
    <text x="25" y="35" class="title"><?php echo htmlspecialchars($titleText); ?></text>
    <?php echo $rows; ?>
</svg>
<?php
$svgOutput = ob_get_clean();
cachePut($cacheFile, $svgOutput);
echo $svgOutput;

// ---------- helpers ----------

/**
 * Return a cached SVG if it exists and is still within its TTL, else null.
 */
function cacheGet(string $file, int $ttl): ?string
{
    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $data = file_get_contents($file);
        if ($data !== false) {
            return $data;
        }
    }
    return null;
}

/**
 * Write the rendered SVG to the cache. Failures (e.g. an unwritable temp dir)
 * are non-fatal: the banner still renders, it just won't be cached.
 * The write is atomic (write-then-rename) so a concurrent request never reads
 * a half-written file.
 */
function cachePut(string $file, string $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }

    // Opportunistically garbage-collect so a flood of unique keys (e.g. random
    // ?title= values) can't fill the disk. Runs on ~2% of writes to stay cheap.
    if (random_int(1, 50) === 1) {
        cacheGc($dir);
    }

    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $data) !== false) {
        @rename($tmp, $file);
    }
}

/**
 * Prune the cache dir: delete entries past their TTL, then, if still over the
 * hard file cap, evict the oldest first. Also sweeps stale temp files left by
 * interrupted writes. All operations are best-effort and never fatal.
 */
function cacheGc(string $dir): void
{
    $now = time();

    // Remove orphaned temp files from interrupted writes (older than 60s).
    foreach ((@glob($dir . '/*.tmp') ?: []) as $tmp) {
        if (($now - (int)@filemtime($tmp)) > 60) {
            @unlink($tmp);
        }
    }

    // Drop anything past its TTL — it would never be served anyway.
    $files = @glob($dir . '/*.svg') ?: [];
    foreach ($files as $f) {
        if (($now - (int)@filemtime($f)) >= CACHE_TTL) {
            @unlink($f);
        }
    }

    // Enforce the hard cap, evicting the oldest entries first.
    $files = @glob($dir . '/*.svg') ?: [];
    if (count($files) > CACHE_MAX_FILES) {
        usort($files, function ($a, $b) {
            return (int)@filemtime($a) <=> (int)@filemtime($b);
        });
        foreach (array_slice($files, 0, count($files) - CACHE_MAX_FILES) as $f) {
            @unlink($f);
        }
    }
}

/**
 * Fetch a user's contributions on a single day.
 *
 * When $repoLimit > 0, also returns a per-repo breakdown with clickable
 * GitHub commits URLs filtered by author + that day (the same format GitHub
 * uses when you click a contribution square on a profile).
 *
 * Returns: ['total' => int, 'repos' => [['nameWithOwner'=>str,'url'=>str,'count'=>int], ...]]
 */
function fetchUserContributionsOnDay(string $login, string $from, string $to, string $token, int $repoLimit): array
{
    $reposFragment = $repoLimit > 0
        ? '
          commitContributionsByRepository(maxRepositories: 100) {
            contributions { totalCount }
            repository { nameWithOwner url isPrivate }
          }'
        : '';

    $query = '
    query($login: String!, $from: DateTime!, $to: DateTime!) {
      user(login: $login) {
        contributionsCollection(from: $from, to: $to) {
          contributionCalendar { totalContributions }
          ' . $reposFragment . '
        }
      }
    }';

    $payload = json_encode([
        'query'     => $query,
        'variables' => ['login' => $login, 'from' => $from, 'to' => $to],
    ]);

    $response = githubGraphql($payload, $token);
    $coll = $response['data']['user']['contributionsCollection'] ?? null;
    if ($coll === null) {
        return ['total' => 0, 'repos' => []];
    }

    $total = $coll['contributionCalendar']['totalContributions'] ?? 0;
    $repos = [];
    $hiddenPrivateCount = 0;
    $hiddenPrivateCommits = 0;
    if ($repoLimit > 0 && !empty($coll['commitContributionsByRepository'])) {
        $built = [];
        foreach ($coll['commitContributionsByRepository'] as $row) {
            $count = $row['contributions']['totalCount'] ?? 0;
            if ($count <= 0) continue;
            $nwo = $row['repository']['nameWithOwner'] ?? null;
            if ($nwo === null) continue;
            $isPrivate = $row['repository']['isPrivate'] ?? false;
            if ($isPrivate) {
                // Counted toward the total (matches GitHub's profile graph,
                // which includes private contributions), but never displayed.
                $hiddenPrivateCount++;
                $hiddenPrivateCommits += $count;
                continue;
            }
            $built[] = [
                'nameWithOwner' => $nwo,
                'url'           => buildCommitsUrl($nwo, $login, $from, $to, true),
                'count'         => $count,
            ];
        }
        // Sort by commit count desc, then name, and apply the limit.
        usort($built, function ($a, $b) {
            if ($a['count'] === $b['count']) return strcmp($a['nameWithOwner'], $b['nameWithOwner']);
            return $b['count'] <=> $a['count'];
        });
        $repos = array_slice($built, 0, $repoLimit);
    }

    return [
        'total'               => $total ?? 0,
        'repos'               => $repos,
        'hiddenPrivateCount'  => $hiddenPrivateCount,
        'hiddenPrivateCommits'=> $hiddenPrivateCommits,
    ];
}

/**
 * Fetch an org's commits on a single day by iterating its public repos.
 * Repos with the most commits on that day are returned when $repoLimit > 0.
 */
function fetchOrgCommitsOnDay(string $org, string $from, string $to, string $token, int $repoLimit): array
{
    $repos = githubRest("/orgs/{$org}/repos?per_page=20&type=public&sort=pushed", $token);
    if (!is_array($repos)) {
        return ['total' => 0, 'repos' => []];
    }

    $total = 0;
    $built = [];
    foreach ($repos as $repo) {
        $owner = $repo['owner']['login'] ?? $org;
        $name  = $repo['name'] ?? '';
        if ($name === '') continue;
        $nwo = "{$owner}/{$name}";

        $commits = githubRest(
            "/repos/{$owner}/{$name}/commits?since={$from}&until={$to}&per_page=100",
            $token
        );
        if (!is_array($commits)) continue;
        $repoCount = count($commits);
        if ($repoCount === 0) continue;

        $total += $repoCount;
        $built[] = [
            'nameWithOwner' => $nwo,
            'url'           => buildCommitsUrl($nwo, $org, $from, $to, false),
            'count'         => $repoCount,
        ];
    }

    usort($built, function ($a, $b) {
        if ($a['count'] === $b['count']) return strcmp($a['nameWithOwner'], $b['nameWithOwner']);
        return $b['count'] <=> $a['count'];
    });

    $reposOut = ($repoLimit > 0) ? array_slice($built, 0, $repoLimit) : [];
    return [
        'total'               => $total,
        'repos'               => $reposOut,
        'hiddenPrivateCount'  => 0,
        'hiddenPrivateCommits'=> 0,
    ];
}

/**
 * Build the GitHub commits URL, matching the format GitHub uses when you
 * click a contribution square, e.g.:
 *   https://github.com/octocat/octocat.github.io/commits?author=octocat&since=2025-07-01T00:00:00Z&until=2025-08-01T23:59:59Z
 *
 * The author= filter is omitted for orgs (orgs don't author commits).
 */
function buildCommitsUrl(string $nameWithOwner, string $author, string $from, string $to, bool $includeAuthor): string
{
    $url = "https://github.com/{$nameWithOwner}/commits?";
    if ($includeAuthor) {
        $url .= 'author=' . rawurlencode($author) . '&';
    }
    $url .= 'since=' . rawurlencode($from) . '&until=' . rawurlencode($to);
    return $url;
}

function githubGraphql(string $payload, string $token): array
{
    $ch = curl_init('https://api.github.com/graphql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: bearer ' . $token,
            'Content-Type: application/json',
            'User-Agent: GitHub-Memories-Banner',
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

function githubRest(string $path, string $token)
{
    $ch = curl_init('https://api.github.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: GitHub-Memories-Banner',
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function renderError(string $message): void
{
    $width = 450;
    $height = 120;
    header('Content-Type: image/svg+xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<svg width="<?php echo $width; ?>" height="<?php echo $height; ?>" viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" xmlns="http://www.w3.org/2000/svg">
    <rect width="<?php echo $width; ?>" height="<?php echo $height; ?>" rx="10" fill="#0d1117" stroke="#30363d"/>
    <text x="25" y="35" class="title" fill="#58a6ff" font-family="Segoe UI, Ubuntu, Sans-Serif" font-size="16" font-weight="600">GitHub Memories - Error</text>
    <text x="25" y="70" fill="#f85149" font-family="Segoe UI, Ubuntu, Sans-Serif" font-size="14"><?php echo htmlspecialchars($message); ?></text>
</svg>
<?php
}