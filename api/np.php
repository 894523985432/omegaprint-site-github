<?php
require dirname(__DIR__) . '/lib/bootstrap.php';

const NP_POSTOMAT = 'f9316480-5f2d-425d-bc2c-ac7cd29decf0';
const NP_PRIVAT_POSTOMAT = '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc';

const NP_BRANCH_TYPES = ['841339c7-591a-42e2-8233-7a0a00f0ed6f', '9a68df70-0267-42a8-bb5c-37f427e36ee4'];
const NP_PAGE = 100;

function np(string $model, string $method, array $props, ?int &$total = null): array
{
    $body = ['modelName' => $model, 'calledMethod' => $method, 'methodProperties' => $props];
    $key = trim((string)config('np_api_key', ''));
    if ($key !== '') $body['apiKey'] = $key;
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($body, JSON_UNESCAPED_UNICODE), 'timeout' => 12, 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('https://api.novaposhta.ua/v2.0/json/', false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    if (!$data || empty($data['success'])) {
        respond(['ok' => false, 'message' => 'Сервіс «Нової пошти» тимчасово недоступний. Спробуйте ще раз.'], 502);
    }
    $total = (int)($data['info']['totalCount'] ?? count($data['data'] ?? []));
    return $data['data'] ?? [];
}

function q(int $max = 60): string
{
    return mb_substr(trim(mb_scrub((string)($_GET['q'] ?? ''), 'UTF-8')), 0, $max);
}

function ref(string $key): string
{
    $v = (string)($_GET[$key] ?? '');
    return preg_match('/^[0-9a-f-]{36}$/i', $v) ? $v : '';
}

header('Cache-Control: public, max-age=300');

switch ($_GET['action'] ?? '') {
    case 'cities':
        $q = q();

        if (mb_strlen($q) < 2 || !preg_match('/^[\p{Cyrillic}\s\'’ʼ.\-]+$/u', $q)) respond(['ok' => true, 'cities' => []]);
        $data = np('Address', 'searchSettlements', ['CityName' => $q, 'Limit' => '15', 'Page' => '1']);
        $cities = [];
        foreach ($data[0]['Addresses'] ?? [] as $a) {
            if (empty($a['DeliveryCity'])) continue;
            $cities[] = ['name' => $a['Present'], 'city_ref' => $a['DeliveryCity'], 'settlement_ref' => $a['Ref'],
                         'warehouses' => (int)($a['Warehouses'] ?? 0)];
        }
        respond(['ok' => true, 'cities' => $cities]);

    case 'warehouses':
        $cityRef = ref('city_ref');
        if ($cityRef === '') respond(['ok' => false, 'message' => 'Оберіть місто.'], 422);
        $postomat = ($_GET['type'] ?? '') === 'postomat';
        $q = q();

        $list = [];
        $total = 0;
        foreach ($postomat ? [NP_POSTOMAT, NP_PRIVAT_POSTOMAT] : NP_BRANCH_TYPES as $type) {
            $props = ['CityRef' => $cityRef, 'TypeOfWarehouseRef' => $type, 'Limit' => (string)NP_PAGE, 'Page' => '1', 'Language' => 'UA'];
            if ($q !== '') $props['FindByString'] = $q;
            $count = 0;
            $rows = np('AddressGeneral', 'getWarehouses', $props, $count);
            $before = count($list);
            foreach ($rows as $w) {
                if (isset($list[$w['Ref']])) continue;

                $isPostomat = in_array($w['TypeOfWarehouse'] ?? '', [NP_POSTOMAT, NP_PRIVAT_POSTOMAT], true);
                if ($isPostomat !== $postomat) continue 2;
                $list[$w['Ref']] = ['name' => $w['Description'], 'ref' => $w['Ref'], 'number' => $w['Number'] ?? ''];
            }

            if (!$rows || count($list) > $before) $total += $count;
        }
        $list = array_values($list);

        usort($list, fn($a, $b) => [$b['number'] === $q, (int)$a['number']] <=> [$a['number'] === $q, (int)$b['number']]);
        $list = array_slice($list, 0, NP_PAGE);
        respond(['ok' => true, 'warehouses' => $list, 'total' => max($total, count($list))]);

    case 'streets':
        $ref = ref('settlement_ref');
        $q = q();
        if ($ref === '' || mb_strlen($q) < 2) respond(['ok' => true, 'streets' => []]);
        $data = np('Address', 'searchSettlementStreets', ['SettlementRef' => $ref, 'StreetName' => $q, 'Limit' => '15']);
        $streets = array_map(fn($s) => $s['Present'], $data[0]['Addresses'] ?? []);
        respond(['ok' => true, 'streets' => $streets]);

    default:
        respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
}
