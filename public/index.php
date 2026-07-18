<?php

declare(strict_types=1);

/**
 * Minimal web interface for the php-aspell spell checker.
 *
 * - GET  /  -> renders the single-page UI.
 * - POST /  (JSON) -> loads the chosen dictionary, checks the submitted text,
 *                     and returns misspellings, suggestions, a corrected
 *                     version of the text, a highlighted preview and timings.
 *
 * Run it with PHP's built-in server:
 *     php -S 127.0.0.1:8080 public/index.php
 */

ini_set('memory_limit', '1536M');

require dirname(__DIR__) . '/vendor/autoload.php';

use Aspell\Config\AspellConfig;
use Aspell\Engine\Speller;

/** Whitelisted dictionaries (never trust a client-supplied path). */
$DICT_ROOT = dirname(__DIR__) . '/dictionaries';
$DICTIONARIES = [
    'en' => ['label' => 'English',            'path' => $DICT_ROOT . '/aspell6-en-2026.02.25-0/en.multi'],
    'fr' => ['label' => 'French — français',  'path' => $DICT_ROOT . '/aspell-fr-0.50-3/fr.multi'],
    'ru' => ['label' => 'Russian — русский',  'path' => $DICT_ROOT . '/aspell6-ru-0.99f7-1/ru.multi'],
    'ar' => ['label' => 'Arabic — العربية',   'path' => $DICT_ROOT . '/aspell6-ar-1.2-0/ar.multi'],
];

const MAX_TEXT_BYTES   = 300_000; // guard against pathological input
const MAX_SUGGEST_WORDS = 60;      // cap unique words we suggest for
const SUGGEST_BUDGET_S  = 8.0;     // wall-clock budget for suggestions

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(handleCheck($DICTIONARIES), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * @param array<string, array{label:string, path:string}> $dictionaries
 * @return array<string, mixed>
 */
function handleCheck(array $dictionaries): array
{
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        throw new \InvalidArgumentException('Malformed request body.');
    }

    $dictKey = (string) ($in['dict'] ?? '');
    if (!isset($dictionaries[$dictKey])) {
        throw new \InvalidArgumentException('Unknown dictionary.');
    }
    $dict = $dictionaries[$dictKey];
    if (!is_file($dict['path'])) {
        throw new \RuntimeException("Dictionary file not found: {$dict['path']}");
    }

    $text = (string) ($in['text'] ?? '');
    if (strlen($text) > MAX_TEXT_BYTES) {
        throw new \InvalidArgumentException('Input too large (limit ' . MAX_TEXT_BYTES . ' bytes).');
    }
    $mode = !empty($in['latex']) ? 'tex' : 'text';
    $wantSuggest = (bool) ($in['suggest'] ?? true);

    // --- Load dictionary --------------------------------------------------
    $speller = new Speller(new AspellConfig());
    $t0 = microtime(true);
    $speller->loadDictionary($dict['path']);
    $loadMs = (int) round((microtime(true) - $t0) * 1000);
    $wordsLoaded = count($speller->getLoadedWords());

    // --- Check ------------------------------------------------------------
    $t0 = microtime(true);
    $found = $speller->checkDocument($text, $mode);
    $checkMs = (int) round((microtime(true) - $t0) * 1000);

    // Aggregate unique misspellings, keeping first-seen order.
    $counts = [];
    foreach ($found as $f) {
        $w = $f['word'];
        $counts[$w] = ($counts[$w] ?? 0) + 1;
    }
    $unique = array_keys($counts);

    // --- Suggestions ------------------------------------------------------
    $suggestByLower = [];
    $misspellings = [];
    $suggestMs = 0;
    $suggestCapped = false;

    $tSug = microtime(true);
    $i = 0;
    foreach ($unique as $w) {
        $sugg = [];
        if ($wantSuggest) {
            if ($i >= MAX_SUGGEST_WORDS || (microtime(true) - $tSug) > SUGGEST_BUDGET_S) {
                $suggestCapped = true;
            } else {
                $sugg = array_slice($speller->suggest($w), 0, 7);
                $suggestByLower[mb_strtolower($w, 'UTF-8')] = $sugg;
                $i++;
            }
        }
        $misspellings[] = ['word' => $w, 'count' => $counts[$w], 'suggestions' => $sugg];
    }
    if ($wantSuggest) {
        $suggestMs = (int) round((microtime(true) - $tSug) * 1000);
    }

    // --- Corrected text + highlighted preview -----------------------------
    if ($unique === []) {
        $corrected   = $text;
        $previewHtml = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    } else {
        $rx          = $speller->misspellingRegex($unique);
        $corrected   = buildCorrectedText($text, $rx, $suggestByLower);
        $previewHtml = buildPreviewHtml($text, $rx, $suggestByLower);
    }

    return [
        'ok' => true,
        'diagnostics' => [
            'dictionary'       => $dictKey,
            'wordsLoaded'      => $wordsLoaded,
            'loadMs'           => $loadMs,
            'checkMs'          => $checkMs,
            'suggestMs'        => $suggestMs,
            'mode'             => $mode,
            'totalOccurrences' => count($found),
            'uniqueCount'      => count($unique),
            'suggestCapped'    => $suggestCapped,
        ],
        'correctedText' => $corrected,
        'previewHtml'   => $previewHtml,
        'misspellings'  => $misspellings,
    ];
}

/**
 * Plain-text correction: replace each misspelling with its top suggestion,
 * preserving the original capitalisation. Pure response data — no markup.
 *
 * @param string                       $text           original (unescaped) text
 * @param string                       $rx             regex from misspellingRegex()
 * @param array<string, list<string>>  $suggestByLower suggestions keyed by lowercased word
 */
function buildCorrectedText(string $text, string $rx, array $suggestByLower): string
{
    return preg_replace_callback($rx, static function (array $m) use ($suggestByLower): string {
        $orig = $m[1];
        $best = $suggestByLower[mb_strtolower($orig, 'UTF-8')][0] ?? null;
        return $best === null ? $orig : matchCase($orig, $best);
    }, $text) ?? $text;
}

/**
 * HTML preview: escape the text, then wrap each misspelling in a <mark> whose
 * tooltip lists suggestions. Presentation only. The pattern only matches
 * letters/apostrophes, which HTML-escaping leaves untouched, so it is safe to
 * run the replacement over the already-escaped text.
 *
 * @param string                       $text           original (unescaped) text
 * @param string                       $rx             regex from misspellingRegex()
 * @param array<string, list<string>>  $suggestByLower suggestions keyed by lowercased word
 */
function buildPreviewHtml(string $text, string $rx, array $suggestByLower): string
{
    $escaped = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

    return preg_replace_callback($rx, static function (array $m) use ($suggestByLower): string {
        $orig  = $m[1];
        $sugg  = $suggestByLower[mb_strtolower($orig, 'UTF-8')] ?? [];
        $title = $sugg === [] ? 'no suggestions' : implode(', ', array_slice($sugg, 0, 5));
        return '<mark title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($orig, ENT_NOQUOTES, 'UTF-8') . '</mark>';
    }, $escaped) ?? $escaped;
}

/** Transfer the capitalisation of $model onto $word (ALLCAPS / Titlecase / as-is). */
function matchCase(string $model, string $word): string
{
    if (mb_strtoupper($model, 'UTF-8') === $model && mb_strtolower($model, 'UTF-8') !== $model) {
        return mb_strtoupper($word, 'UTF-8');
    }
    $first = mb_substr($model, 0, 1, 'UTF-8');
    if (mb_strtoupper($first, 'UTF-8') === $first && mb_strtolower($first, 'UTF-8') !== $first) {
        return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
    }
    return $word;
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$sampleTex = <<<'TEX'
\documentclass{article}
\begin{document}
\section{A quick tset}
This sentance has a fwe misspeled words to demonstrate the
spell chekcer. Mathematics like $x + y = z$ should be ignord,
and \cite{knuth1984} citations are skipepd.
\end{document}
TEX;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>php-aspell — spell checker</title>
<style>
  :root {
    --bg:#0f1115; --panel:#171a21; --panel2:#1e222b; --line:#2b303b;
    --fg:#e6e9ef; --muted:#9aa3b2; --accent:#5aa9ff; --bad:#ff6b6b;
    --good:#5ad19a; --chip:#242a35;
  }
  * { box-sizing:border-box; }
  body {
    margin:0; background:var(--bg); color:var(--fg);
    font:14px/1.5 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  }
  header { padding:14px 20px; border-bottom:1px solid var(--line); display:flex; align-items:baseline; gap:12px; }
  header h1 { font-size:16px; margin:0; font-weight:600; }
  header .sub { color:var(--muted); font-size:12px; }
  main { display:grid; grid-template-columns:1fr 1fr; gap:16px; padding:16px 20px; max-width:1400px; }
  @media (max-width:920px){ main{ grid-template-columns:1fr; } }
  .panel { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:14px; }
  .panel h2 { font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin:0 0 10px; }
  label { display:block; font-size:12px; color:var(--muted); margin:0 0 4px; }
  textarea {
    width:100%; min-height:280px; resize:vertical; background:var(--panel2); color:var(--fg);
    border:1px solid var(--line); border-radius:8px; padding:10px;
    font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  }
  .row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:12px; }
  select, button {
    background:var(--panel2); color:var(--fg); border:1px solid var(--line);
    border-radius:8px; padding:8px 12px; font-size:13px; cursor:pointer;
  }
  button.primary { background:var(--accent); color:#08111f; border-color:var(--accent); font-weight:600; }
  button.primary:disabled { opacity:.55; cursor:progress; }
  button.ghost { padding:6px 10px; font-size:12px; }
  .check { display:flex; align-items:center; gap:6px; color:var(--fg); }
  .check input { accent-color:var(--accent); }
  .diag { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
  .stat { background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:6px 10px; font-size:12px; }
  .stat b { color:var(--accent); font-weight:600; }
  .preview {
    background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:10px;
    white-space:pre-wrap; word-break:break-word; max-height:240px; overflow:auto;
    font:13px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  }
  mark { background:rgba(255,107,107,.22); color:var(--bad); border-bottom:1px dashed var(--bad); border-radius:2px; padding:0 1px; }
  .misslist { max-height:360px; overflow:auto; display:flex; flex-direction:column; gap:8px; }
  .miss { background:var(--panel2); border:1px solid var(--line); border-radius:8px; padding:8px 10px; }
  .miss .w { font-weight:600; font-family:ui-monospace,monospace; }
  .miss .cnt { color:var(--muted); font-size:11px; margin-left:6px; }
  .miss .none { color:var(--muted); font-size:12px; font-style:italic; }
  .chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
  .chip { background:var(--chip); border:1px solid var(--line); border-radius:999px; padding:3px 10px; font-size:12px; cursor:pointer; }
  .chip:hover { border-color:var(--accent); color:var(--accent); }
  .copied { color:var(--good); font-size:12px; margin-left:8px; }
  .err { color:var(--bad); }
  .empty { color:var(--muted); }
  .hint { color:var(--muted); font-size:11px; margin-top:6px; }
</style>
</head>
<body>
<header>
  <h1>php-aspell</h1>
  <span class="sub">pure-PHP spell checker · LaTeX-aware</span>
</header>

<main>
  <section class="panel">
    <h2>Input</h2>
    <label for="text">LaTeX / plain text</label>
    <textarea id="text" spellcheck="false"><?= htmlspecialchars($sampleTex, ENT_NOQUOTES, 'UTF-8') ?></textarea>
    <div class="row">
      <div>
        <label for="dict">Dictionary</label>
        <select id="dict">
          <?php foreach ($DICTIONARIES as $k => $d): ?>
            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($d['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <label class="check" style="margin-top:18px;"><input type="checkbox" id="latex" checked> LaTeX mode</label>
      <label class="check" style="margin-top:18px;"><input type="checkbox" id="suggest" checked> Suggestions</label>
      <button class="primary" id="run" style="margin-top:18px;">Check spelling</button>
    </div>
    <div class="hint">Runs server-side PHP. The first check of a large dictionary (Arabic ≈ 1M words) can take a few seconds to load.</div>
  </section>

  <section class="panel">
    <h2>Result</h2>
    <div id="diag" class="diag"></div>

    <label for="corrected">Corrected text <span class="hint">(top suggestion auto-applied)</span></label>
    <textarea id="corrected" spellcheck="false" readonly></textarea>
    <div class="row">
      <button class="ghost" id="copy">Copy corrected text</button>
      <span id="copied" class="copied" style="display:none">copied ✓</span>
    </div>

    <h2 style="margin-top:16px;">Highlighted preview</h2>
    <div id="preview" class="preview empty">Run a check to see results.</div>

    <h2 style="margin-top:16px;">Misspellings &amp; suggestions</h2>
    <div id="miss" class="misslist"><div class="empty">—</div></div>
  </section>
</main>

<script>
const $ = (id) => document.getElementById(id);

async function run() {
  const btn = $('run');
  btn.disabled = true; btn.textContent = 'Checking…';
  $('diag').innerHTML = '';
  try {
    const res = await fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        dict: $('dict').value,
        text: $('text').value,
        latex: $('latex').checked,
        suggest: $('suggest').checked,
      }),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Request failed');
    render(data);
  } catch (e) {
    $('diag').innerHTML = '<div class="stat err">Error: ' + escapeHtml(e.message) + '</div>';
    $('preview').className = 'preview empty';
    $('preview').textContent = '—';
  } finally {
    btn.disabled = false; btn.textContent = 'Check spelling';
  }
}

function render(d) {
  const g = d.diagnostics;
  const stat = (label, val) => `<div class="stat">${label} <b>${val}</b></div>`;
  $('diag').innerHTML =
    stat('dict', g.dictionary) +
    stat('mode', g.mode) +
    stat('words loaded', g.wordsLoaded.toLocaleString()) +
    stat('misspellings', g.totalOccurrences + ' (' + g.uniqueCount + ' unique)') +
    stat('load', g.loadMs + ' ms') +
    stat('check', g.checkMs + ' ms') +
    stat('suggest', g.suggestMs + ' ms' + (g.suggestCapped ? ' (capped)' : ''));

  $('corrected').value = d.correctedText;

  const prev = $('preview');
  if (g.uniqueCount === 0) {
    prev.className = 'preview empty';
    prev.textContent = 'No misspellings found. ✓';
  } else {
    prev.className = 'preview';
    prev.innerHTML = d.previewHtml;
  }

  const box = $('miss');
  if (!d.misspellings.length) {
    box.innerHTML = '<div class="empty">No misspellings. ✓</div>';
    return;
  }
  box.innerHTML = '';
  for (const m of d.misspellings) {
    const el = document.createElement('div');
    el.className = 'miss';
    let html = '<span class="w">' + escapeHtml(m.word) + '</span>'
      + '<span class="cnt">×' + m.count + '</span>';
    if (m.suggestions && m.suggestions.length) {
      html += '<div class="chips">' +
        m.suggestions.map(s => '<span class="chip" data-w="' + escapeAttr(m.word)
          + '" data-s="' + escapeAttr(s) + '">' + escapeHtml(s) + '</span>').join('') +
        '</div>';
    } else {
      html += '<div class="none">no suggestions</div>';
    }
    el.innerHTML = html;
    box.appendChild(el);
  }
  // Click a suggestion to apply it to the corrected text (all occurrences).
  box.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => {
    applySuggestion(c.dataset.w, c.dataset.s);
  }));
}

function applySuggestion(word, suggestion) {
  const rx = new RegExp('(?<![\\p{L}\'])(' + escapeRegex(word) + ')(?![\\p{L}\'])', 'giu');
  $('corrected').value = $('corrected').value.replace(rx, (m) => matchCase(m, suggestion));
}

function matchCase(model, word) {
  if (model.toUpperCase() === model && model.toLowerCase() !== model) return word.toUpperCase();
  const f = model[0];
  if (f && f.toUpperCase() === f && f.toLowerCase() !== f) return word[0].toUpperCase() + word.slice(1);
  return word;
}

$('copy').addEventListener('click', async () => {
  try {
    await navigator.clipboard.writeText($('corrected').value);
    const c = $('copied'); c.style.display = 'inline';
    setTimeout(() => c.style.display = 'none', 1500);
  } catch { /* clipboard may be blocked on insecure origins */ }
});

$('run').addEventListener('click', run);
$('text').addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') run();
});

const escapeHtml = (s) => s.replace(/[&<>]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;' }[c]));
const escapeAttr = (s) => s.replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
</script>
</body>
</html>
