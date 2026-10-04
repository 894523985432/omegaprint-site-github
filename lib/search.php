<?php
const SEARCH_MAX_HITS = 5000;

const SEARCH_WEIGHTS = '10.0, 8.0, 4.0, 2.0, 1.0';

const SEARCH_DEFAULT_SYNONYMS = <<<TXT
шредер, знищувач, уничтожитель, shredder
ламінатор, ламинатор, laminator
біндер, биндер, брошурувальник, брошюровщик, палітур, переплет
різак, резак, тример, cutter
гільйотин, гильотин
плотер, плоттер, plotter
термоклей, клейова, клеевая
степлер, stapler
фальц, фальцовщик, фальцювальник
бігув, бігов, бигов
діркопробивач, дырокол, дирокол
люверс, заклепочник
ніж, нож, лезо
TXT;

function searchLower(string $s): string
{
    return mb_strtolower($s, 'UTF-8');
}

function searchSynonyms(PDO $pdo): string
{
    $raw = $pdo->query("SELECT value FROM site_meta WHERE key = 'search_synonyms'")->fetchColumn();
    return $raw === false ? SEARCH_DEFAULT_SYNONYMS : (string)$raw;
}

function searchSynonymGroups(PDO $pdo): array
{
    $groups = [];
    foreach (preg_split('/\R/u', searchSynonyms($pdo)) as $line) {
        $words = array_values(array_filter(array_map(fn($w) => trim(searchLower($w)), explode(',', $line)), fn($w) => $w !== ''));
        if (count($words) > 1) $groups[] = $words;
    }
    return $groups;
}

function searchTokens(string $text): array
{
    $parts = preg_split('/[^\p{L}\p{N}]+/u', searchLower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_slice($parts, 0, 8);
}

function searchStem(string $word): string
{
    if (mb_strlen($word) < 5 || !preg_match('/[а-яіїєґ]/u', $word)) return $word;
    $stem = preg_replace('/[аеиіоуюяїєйь]{1,2}$/u', '', $word);
    return mb_strlen($stem) >= 4 ? $stem : $word;
}

function searchTranslit(string $word): string
{
    static $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z',
        'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
        'ь' => '', 'ъ' => '', 'ы' => 'y', 'э' => 'e', 'ё' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ʼ' => '', "'" => '',
    ];
    return strtr($word, $map);
}

function searchSwitchLayout(string $text): string
{
    static $en = "qwertyuiop[]asdfghjkl;'zxcvbnm,.";
    static $uk = "йцукенгшщзхїфівапролджєячсмитьбю";
    $from = mb_str_split($en . $uk);
    $to = mb_str_split($uk . $en);
    return strtr(searchLower($text), array_combine($from, $to));
}

function searchLookalike(string $word, bool $toLatin): string
{
    static $cyr = ['а', 'в', 'е', 'к', 'м', 'н', 'о', 'р', 'с', 'т', 'х', 'і', 'у'];
    static $lat = ['a', 'b', 'e', 'k', 'm', 'h', 'o', 'p', 'c', 't', 'x', 'i', 'y'];
    return $toLatin ? str_replace($cyr, $lat, $word) : str_replace($lat, $cyr, $word);
}

function searchStartsWith(string $haystack, string $needle): bool
{
    return $needle !== '' && mb_substr($haystack, 0, mb_strlen($needle)) === $needle;
}

function searchTerm(string $phrase): ?string
{
    $tokens = searchTokens($phrase);
    if (!$tokens) return null;
    $last = $tokens[count($tokens) - 1];
    return '"' . implode(' ', $tokens) . '"' . (mb_strlen($last) > 1 ? '*' : '');
}

function searchMatchQuery(PDO $pdo, string $text, bool $any = false): ?string
{
    $groups = searchSynonymGroups($pdo);
    $parts = [];
    $words = array_slice(preg_split('/\s+/u', trim(searchLower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
    foreach ($words as $word) {
        $tokens = searchTokens($word);
        if (!$tokens) continue;
        if (count($tokens) > 1) {

            $terms = array_values(array_unique(array_filter([searchTerm(implode(' ', $tokens)), searchTerm(implode('', $tokens))])));
            $parts[] = '(' . implode(' OR ', $terms) . ')';
            continue;
        }
        $token = $tokens[0];
        $stem = searchStem($token);
        $variants = [$stem];

        if (preg_match('/^(\p{L}+)(\d+)$/u', $token, $m)) $variants[] = $m[1] . ' ' . $m[2];

        if (preg_match('/\d/', $token) && preg_match('/\p{L}/u', $token)) {
            foreach ([searchLookalike($token, true), searchLookalike($token, false)] as $alt) {
                if ($alt === $token) continue;
                $variants[] = $alt;
                if (preg_match('/^(\p{L}+)(\d+)$/u', $alt, $m)) $variants[] = $m[1] . ' ' . $m[2];
            }
        }
        if (preg_match('/^[а-яіїєґё]+$/u', $token) && mb_strlen($token) >= 3) {
            $variants[] = searchTranslit($stem);
        }
        if (mb_strlen($stem) >= 3) {
            foreach ($groups as $group) {
                foreach ($group as $word) {
                    if (searchStartsWith($word, $stem) || searchStartsWith($stem, $word)) {
                        array_push($variants, ...$group);
                        break;
                    }
                }
            }
        }
        $terms = array_values(array_unique(array_filter(array_map('searchTerm', $variants))));
        if ($terms) $parts[] = '(' . implode(' OR ', $terms) . ')';
    }
    return $parts ? implode($any ? ' OR ' : ' AND ', $parts) : null;
}

function searchFixLetters(string $text): string
{
    return preg_replace_callback('/\S*[а-яіїєґА-ЯІЇЄҐ]\S*/u', fn($m) => strtr($m[0], ['i' => 'і', 'I' => 'І']), $text);
}

function searchStamp(PDO $pdo): string
{
    $p = $pdo->query('SELECT COUNT(*), MAX(updated_at), SUM(LENGTH(name)), SUM(category_id) FROM products')->fetch(PDO::FETCH_NUM);
    $s = $pdo->query('SELECT COUNT(*), SUM(LENGTH(value)), SUM(attr_id) FROM product_specs')->fetch(PDO::FETCH_NUM);
    $c = $pdo->query("SELECT group_concat(id || ':' || COALESCE(parent_id, 0) || ':' || name, '|') FROM categories")->fetchColumn();
    return md5(json_encode([$p, $s, $c]));
}

function searchEnsure(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
            title, sku, brand, category, specs, tokenize = 'unicode61 remove_diacritics 2', prefix = '2 3 4')");
    } catch (Throwable $e) {
        error_log('search: FTS5 недоступний - ' . $e->getMessage());
        return $ready = false;
    }
    $stamp = searchStamp($pdo);
    $saved = $pdo->query("SELECT value FROM site_meta WHERE key = 'search_stamp'")->fetchColumn();
    if ($saved !== $stamp) searchRebuild($pdo, $stamp);
    return $ready = true;
}

function searchRebuild(PDO $pdo, string $stamp): void
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {

        if ($pdo->query("SELECT value FROM site_meta WHERE key = 'search_stamp'")->fetchColumn() === $stamp) {
            $pdo->exec('COMMIT');
            return;
        }
        $cats = [];
        foreach ($pdo->query('SELECT id, parent_id, name FROM categories')->fetchAll() as $c) $cats[(int)$c['id']] = $c;
        $catPath = function (?int $id) use ($cats): string {
            $names = [];
            while ($id !== null && isset($cats[$id]) && count($names) < 6) {
                $names[] = $cats[$id]['name'];
                $id = $cats[$id]['parent_id'] === null ? null : (int)$cats[$id]['parent_id'];
            }
            return implode(' / ', $names);
        };
        $specs = [];
        $brands = [];
        foreach ($pdo->query('SELECT s.product_id, s.value, a.code, a.name, a.type FROM product_specs s JOIN spec_attrs a ON a.id = s.attr_id')->fetchAll() as $s) {
            $pid = (int)$s['product_id'];
            if ($s['code'] === 'brand') { $brands[$pid] = $s['value']; continue; }
            if ($s['type'] === 'enum') $specs[$pid][] = $s['value'];
            elseif ($s['type'] === 'bool' && $s['value'] === '1') $specs[$pid][] = $s['name'];
        }
        $pdo->exec('DELETE FROM search_index');
        $ins = $pdo->prepare('INSERT INTO search_index (rowid, title, sku, brand, category, specs) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($pdo->query('SELECT id, sku, name, category_id, description FROM products')->fetchAll() as $p) {
            $id = (int)$p['id'];
            $brand = $brands[$id] ?? (preg_match('/Виробник:\s*([^.\n]+)/u', (string)$p['description'], $m) ? trim($m[1]) : '');
            $ins->execute([$id, searchFixLetters($p['name']), $p['sku'], $brand,
                           $catPath($p['category_id'] === null ? null : (int)$p['category_id']), implode(' ', $specs[$id] ?? [])]);
        }
        $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('search_stamp', ?)
                       ON CONFLICT (key) DO UPDATE SET value = excluded.value")->execute([$stamp]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function searchProducts(PDO $pdo, string $text): ?array
{
    if (!searchEnsure($pdo)) return null;
    $run = function (?string $match) use ($pdo): array {
        if ($match === null) return [];
        try {
            $st = $pdo->prepare('SELECT rowid FROM search_index WHERE search_index MATCH ?
                                 ORDER BY bm25(search_index, ' . SEARCH_WEIGHTS . ') LIMIT ' . SEARCH_MAX_HITS);
            $st->execute([$match]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('search: ' . $e->getMessage() . ' | ' . $match);
            return [];
        }
    };
    $ids = $run(searchMatchQuery($pdo, $text));
    if ($ids) return ['ids' => $ids, 'note' => '', 'query' => $text];

    $switched = searchSwitchLayout($text);
    if ($switched !== searchLower($text)) {
        $ids = $run(searchMatchQuery($pdo, $switched));
        if ($ids) return ['ids' => $ids, 'note' => 'layout', 'query' => $switched];
    }

    $fixed = searchFixTypos($pdo, $text, $run);
    if ($fixed !== null) {
        $ids = $run(searchMatchQuery($pdo, $fixed));
        if ($ids) return ['ids' => $ids, 'note' => 'typo', 'query' => $fixed];
    }

    $loose = $fixed ?? $text;
    if (count(searchTokens($loose)) > 1) {
        $ids = $run(searchMatchQuery($pdo, $loose, true));
        if ($ids) return ['ids' => $ids, 'note' => 'partial', 'query' => $loose];
    }
    return ['ids' => [], 'note' => '', 'query' => $text];
}

function searchTrigrams(string $word): array
{
    $chars = mb_str_split($word);
    $out = [];
    for ($i = 0, $n = count($chars) - 2; $i < $n; $i++) $out[$chars[$i] . $chars[$i + 1] . $chars[$i + 2]] = true;
    return $out;
}

function searchFold(string $word): string
{
    return strtr($word, ['і' => 'и', 'ї' => 'и', 'ы' => 'и', 'є' => 'е', 'э' => 'е', 'ё' => 'е', 'ґ' => 'г', 'ъ' => '', 'ь' => '', "'" => '', 'ʼ' => '']);
}

function searchDistance(string $a, string $b): int
{
    $a = mb_str_split($a);
    $b = mb_str_split($b);
    $prev = range(0, count($b));
    foreach ($a as $i => $ca) {
        $cur = [$i + 1];
        foreach ($b as $j => $cb) {
            $cost = min($prev[$j] + ($ca === $cb ? 0 : 1), $prev[$j + 1] + 1, $cur[$j] + 1);

            if ($i > 0 && $j > 0 && $ca === $b[$j - 1] && $a[$i - 1] === $cb && isset($before)) $cost = min($cost, $before[$j - 1] + 1);
            $cur[] = $cost;
        }
        $before = $prev;
        $prev = $cur;
    }
    return $prev[count($b)];
}

function searchVocabulary(PDO $pdo): array
{
    static $vocab = null;
    if ($vocab !== null) return $vocab;
    $vocab = [];
    try {
        $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS search_vocab USING fts5vocab(search_index, 'row')");
        foreach ($pdo->query('SELECT term, doc FROM search_vocab')->fetchAll() as $row) {
            $term = (string)$row['term'];
            if (mb_strlen($term) >= 4 && !preg_match('/\d/', $term)) $vocab[$term] = (int)$row['doc'];
        }
    } catch (Throwable $e) {
        error_log('search: словник недоступний - ' . $e->getMessage());
    }
    return $vocab;
}

function searchClosestWord(PDO $pdo, string $word): ?string
{
    $len = mb_strlen($word);
    if ($len < 4 || preg_match('/\d/', $word)) return null;
    $maxDistance = $len <= 5 ? 1 : 2;
    $forms = [searchFold(searchStem($word))];
    if (preg_match('/^[а-яіїєґё]+$/u', $word)) $forms[] = searchTranslit($word);
    $best = null;
    $bestScore = 0.0;
    foreach (searchVocabulary($pdo) as $term => $docs) {
        $termForm = searchFold(searchStem($term));
        foreach ($forms as $form) {
            if (abs(mb_strlen($termForm) - mb_strlen($form)) > $maxDistance) continue;
            $a = searchTrigrams($form);
            $b = searchTrigrams($termForm);
            $common = count(array_intersect_key($a, $b));

            if (!$common && (mb_strlen($form) > 6 || mb_substr($form, 0, 1) !== mb_substr($termForm, 0, 1))) continue;
            $distance = searchDistance($form, $termForm);
            if ($distance > $maxDistance) continue;

            $score = 2 * $common / (count($a) + count($b)) + (3 - $distance) + min($docs, 500) / 100000;
            if ($score > $bestScore) { $bestScore = $score; $best = $term; }
        }
    }
    return $best;
}

function searchFixTypos(PDO $pdo, string $text, callable $run): ?string
{
    $words = array_slice(preg_split('/\s+/u', trim(searchLower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
    $changed = false;
    foreach ($words as $i => $word) {
        if (count(searchTokens($word)) !== 1 || $run(searchMatchQuery($pdo, $word))) continue;
        $closest = searchClosestWord($pdo, searchTokens($word)[0]);
        if ($closest !== null) { $words[$i] = $closest; $changed = true; }
    }
    return $changed ? implode(' ', $words) : null;
}

function searchHitsTable(PDO $pdo, array $ids): void
{
    $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS search_hits (id INTEGER PRIMARY KEY, pos INTEGER NOT NULL)');
    $pdo->exec('DELETE FROM search_hits');
    foreach (array_chunk($ids, 400, true) as $chunk) {
        $rows = [];
        foreach ($chunk as $pos => $id) $rows[] = '(' . (int)$id . ',' . (int)$pos . ')';
        $pdo->exec('INSERT OR IGNORE INTO search_hits (id, pos) VALUES ' . implode(',', $rows));
    }
}

function searchCategories(PDO $pdo, string $text, int $limit): array
{
    $stems = array_map('searchStem', searchTokens($text));
    if (!$stems) return [];
    $out = [];
    foreach ($pdo->query('SELECT c.id, c.name, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS n FROM categories c ORDER BY c.sort, c.name')->fetchAll() as $c) {
        $words = searchTokens(searchFixLetters($c['name']));
        foreach ($stems as $stem) {
            $hit = false;
            foreach ($words as $w) if (searchStartsWith($w, $stem)) { $hit = true; break; }
            if (!$hit) continue 2;
        }
        $out[] = ['id' => (int)$c['id'], 'name' => $c['name']];
        if (count($out) >= $limit) break;
    }
    return $out;
}
