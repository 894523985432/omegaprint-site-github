<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/uploads.php';

const CONTENT_TAGS = ['b', 'strong', 'i', 'em', 'u', 's', 'br', 'a', 'p', 'ul', 'ol', 'li', 'span', 'div', 'h2', 'h3', 'h4', 'h5', 'h6'];

function sanitizeHtml(string $html): string
{
    $html = mb_scrub($html, 'UTF-8');
    if (trim($html) === '') return '';
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('root');
    if (!$root) return htmlspecialchars(strip_tags($html));
    cleanNode($root);
    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return trim($out);
}

function cleanNode(DOMNode $node): void
{
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $child = $node->childNodes->item($i);
        if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
            $node->removeChild($child);
            continue;
        }
        if (!$child instanceof DOMElement) continue;
        $tag = strtolower($child->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'template'], true)) {
            $node->removeChild($child);
            continue;
        }
        cleanNode($child);
        if (!in_array($tag, CONTENT_TAGS, true)) {

            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $node->removeChild($child);
            continue;
        }
        $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
        foreach (iterator_to_array($child->attributes) as $attr) $child->removeAttribute($attr->name);
        if ($tag === 'a' && $href !== '' && preg_match('#^(https?://|tel:|mailto:|/|\#|[a-z0-9_\-./]+(\?[^:]*)?$)#i', $href)
            && !preg_match('#^\s*(javascript|data|vbscript):#i', $href)) {
            $child->setAttribute('href', $href);
            if (preg_match('#^https?://#i', $href)) {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener');
            }
        }
    }
}

startSession();
requireSameOrigin();
$action = $_GET['action'] ?? '';

try {
    $pdo = db();

    if ($action === 'all') {
        header('Cache-Control: no-cache');
        $out = [];
        foreach ($pdo->query('SELECT key, type, value FROM site_content') as $row) {
            $out[$row['key']] = ['type' => $row['type'], 'value' => $row['value']];
        }
        respond(['ok' => true, 'content' => (object)$out]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
    }
    $user = requireAdmin();
    $key = (string)($_POST['key'] ?? '');
    if (!preg_match('/^[a-z0-9_.:-]{1,80}$/', $key)) respond(['ok' => false, 'message' => 'Некоректний ключ.'], 422);

    $st = $pdo->prepare('SELECT type, value FROM site_content WHERE key = ?');
    $st->execute([$key]);
    $old = $st->fetch();

    switch ($action) {
        case 'save':
            $type = ($_POST['type'] ?? 'html') === 'image' ? 'image' : 'html';
            if ($type === 'image') {
                if (!hasUpload('image')) respond(['ok' => false, 'errors' => ['image' => 'Оберіть картинку.']], 422);
                $value = saveImageUpload($_FILES['image'], 'content');
            } else {
                $value = sanitizeHtml((string)($_POST['value'] ?? ''));
                if (mb_strlen($value) > 50000) respond(['ok' => false, 'message' => 'Текст задовгий.'], 422);
            }
            $pdo->prepare('INSERT INTO site_content (key, type, value, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                           ON CONFLICT(key) DO UPDATE SET type = excluded.type, value = excluded.value,
                           updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP')
                ->execute([$key, $type, $value, $user['id']]);
            if ($old && $old['type'] === 'image' && $old['value'] !== $value) deleteImageUpload($old['value']);
            respond(['ok' => true, 'type' => $type, 'value' => $value]);

        case 'reset':
            $pdo->prepare('DELETE FROM site_content WHERE key = ?')->execute([$key]);
            if ($old && $old['type'] === 'image') deleteImageUpload($old['value']);
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/content.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
