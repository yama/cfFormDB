<?php

/**
 * cfFromDB
 *
 * cfFormMailerで投稿された情報を記録、表示、CSV出力
 *
 * @author		Clefarray Factory
 * @version	1.0.2
 * @internal	@properties  &viewFields=一覧画面で表示する項目;text; &ignoreFields=無視する項目;text; &defaultView=デフォルト画面;list;list,csv;list &sel_csv_fields=CSV出力項目を選択;list;1,0;1 &headLabels=表示や出力時のヘッダラベル<br>【書式】name|ラベル,name2|ラベル2,…;textarea;
 *
 */
class cfFormDB
{
    private array $data;
    private string $version = '1.0.1';
    private array $ignoreParams;
    private array $headLabel;

    public function __construct()
    {
        global $manager_theme, $content;

        evo()->loadExtension('MakeTable');

        $this->data['theme']     = '/' . $manager_theme;
        $this->data['posturl']   = 'index.php?a=112&id=' . $content['id'];
        $this->data['pagetitle'] = $content['name'] . ' v' . $this->version;

        // CSRF トークンをテンプレートに渡す（csrf_token.php の関数を使用）
        $this->data['csrf_token'] = function_exists('getCurrentCsrfToken') ? getCurrentCsrfToken() : '';
        $this->data['manager_url'] = defined('MODX_MANAGER_URL') ? MODX_MANAGER_URL : 'manager/';


        $ignoreParamsRaw = event()->params['ignoreFields'] ?? '';
        if (!empty($ignoreParamsRaw)) {
            $this->ignoreParams = explode(',', $ignoreParamsRaw);
        } else {
            $this->ignoreParams = [];
        }

        /*
         * ラベルを任意に設定できるように調整。
         */
        $this->headLabel = [];
        if (empty(event()->params['headLabels'])) {
            return;
        }
        $headLabels = explode(',', event()->params['headLabels']);
        foreach ($headLabels as $item) {
            preg_match('/(.+)[|;](.+)/', $item, $m);
            if (isset($m[1], $m[2])) {
                $this->headLabel[$m[1]] = $m[2];
            }
        }
    }

    public function action(): bool
    {
        if (!IN_MANAGER_MODE) {
            return false;
        }

        switch (postv('mode')) {
            case "allfields":
                $this->viewAllFields();
                break;
            case "delete":
                $this->delete();
                break;
            case "create_table":
                $this->createTable();
                break;
            case "csv":
                $this->csv();
                break;
            case "csv_generate":
                $this->generateCSV();
                break;
            default:
                $this->defaultAction();
                break;
        }

        $this->view();
        return true;
    }

    private function defaultAction(): bool
    {
        global $content;

        if (!$this->ifTableExists()) {
            $this->data['content'] = $this->parser(
                $this->loadTemplate('tablecreate.tpl'),
                $this->data
            );
            return false;
        }

        $rs = db()->query(sprintf(
            "SHOW COLUMNS FROM %s LIKE 'field'",
            evo()->getFullTableName('cfformdb_detail')
        ));
        $column = db()->getRow($rs);
        if (($column['Type'] ?? '') === 'varchar(255)') {
            db()->query(sprintf(
                "ALTER TABLE %s MODIFY field VARCHAR(191) NOT NULL",
                evo()->getFullTableName('cfformdb_detail')
            ));
            $this->data['content'] = $this->parser(
                $this->loadTemplate('fieldmodify.tpl'),
                $this->data
            );
            return false;
        }

        $defaultView = event()->params['defaultView'] ?? 'list';
        if ($defaultView === 'csv' && !isset($_GET['mode'])) {
            $this->csv();
            return true;
        }

        $rs = db()->select('postid, created', '[+prefix+]cfformdb', '', 'created DESC');
        if (db()->count($rs) <= 0) {
            $this->data['content'] = '<div class="sectionBody">データはありません</div>';
            return true;
        }

        $viewParams = event()->params['viewFields'] ?? '';
        $viewParamsWhere = [];
        if ($viewParams) {
            $viewParamsArr = explode(",", str_replace(" ", "", $viewParams));
            foreach ($viewParamsArr as $val) {
                $viewParamsWhere[] = "'" . db()->escape($val) . "'";
            }
        }

        $total   = db()->count($rs);
        $count   = isset($_GET['ct'])  ? intval($_GET['ct'])  : 30;
        $page    = isset($_GET['cfp']) ? intval($_GET['cfp']) : 1;
        $start   = ($page - 1) * $count;
        $maxPage = (int)ceil($total / $count);
        $pageNav = [];
        if ($maxPage > 1) {
            if ($page > 1) {
                $pageNav[] = sprintf(
                    '<a href="%s&amp;cfp=%d&amp;ct=%d">&laquo;前</a>',
                    $this->data['posturl'], ($page - 1), $count
                );
            }
            for ($i = 1; $i <= $maxPage; $i++) {
                if ($i == $page) {
                    $pageNav[] = sprintf('<strong>%d</strong>', $i);
                } else {
                    $pageNav[] = sprintf(
                        '<a href="%s&amp;cfp=%d&amp;ct=%d">%d</a>',
                        $this->data['posturl'], $i, $count, $i
                    );
                }
            }
            if ($page < $maxPage) {
                $pageNav[] = sprintf(
                    '<a href="%s&amp;cfp=%d&amp;ct=%d">次&raquo;</a>',
                    $this->data['posturl'], ($page + 1), $count
                );
            }
        }
        $params['pageNav'] = implode('&nbsp;|&nbsp;', $pageNav);

        $rs         = db()->select('postid, created', '[+prefix+]cfformdb', '', 'created DESC', $start . ',' . $count);
        $field_keys = [];
        $records    = [];
        $loop = 0;
        while ($buf = db()->getRow($rs)) {
            $where     = 'postid=' . (int)$buf['postid'] . (count($viewParamsWhere) ? ' AND field IN (' . implode(",", $viewParamsWhere) . ')' : '');
            $detail_rs = db()->select('field,value', '[+prefix+]cfformdb_detail', $where, '`rank` ASC');
            $records[$loop]['id'] = $buf['postid'];
            while ($detail_buf = db()->getRow($detail_rs)) {
                if (in_array($detail_buf['field'], $this->ignoreParams)) continue;
                if (100 < mb_strlen($detail_buf['value'], 'utf8'))
                    $detail_buf['value'] = mb_substr($detail_buf['value'], 0, 100, 'utf8') . ' ...';
                $records[$loop][$detail_buf['field']] = $detail_buf['value'];
                $field_keys[$detail_buf['field']]     = $this->getLabel($detail_buf['field']);
            }
            $records[$loop]['created'] = $buf['created'];
            $records[$loop]['view']    = '<a href="[+posturl+]" onclick="submitAction(\'allfields\', ' . (int)$buf['postid'] . ');return false;"><img src="[+icons_preview_resource+]" />詳細表示</a>';
            $records[$loop]['delete']  = '<a href="[+posturl+]" onclick="submitAction(\'delete\', ' . (int)$buf['postid'] . ');return false;"><img src="[+icons_delete+]" />削除</a>';
            $loop++;
        }

        $tbl = new MakeTable();
        $tbl->setTableClass('grid');
        $tbl->setRowHeaderClass('gridHeader');
        $tbl->setRowRegularClass('gridItem');
        $tbl->setRowAlternateClass('gridAltItem');
        $listTableHeader = array_merge(
            ['id' => 'ID', 'created' => '投稿日時', 'view' => '表示', 'delete' => '削除'],
            $field_keys
        );

        $params['countlist'] = '';
        foreach ([30, 50, 100] as $val) {
            $params['countlist'] .= sprintf('<option value="%d"%s>%d件</option>', $val, ($val == $count ? ' selected="selected"' : ''), $val) . "\n";
        }
        $params['moduleid']    = $content['id'];
        $params['list']        = $this->parser($tbl->create($records, $listTableHeader), $this->data);
        $params['total']       = $total;
        $this->data['content'] = $this->parser($this->loadTemplate('list.tpl'), $params);
        $this->data['page']    = $page;
        $this->data['count']   = $count;
        $this->data['add_buttons'] = $this->parser(
            [
                '<li><a href="#" onclick="submitAction(\'csv\',\'\');return false;"><img src="[+icons_save+]" /> CSV出力</a></li>',
                '<li><a href="[+posturl+]"><img src="[+icons_refresh+]" /> 再読み込み</a></li>',
                '<li><a href="index.php?a=2"><img src="[+icons_cancel+]" /> 閉じる</a></li>'
            ],
            $this->data
        );
        return true;
    }

    private function viewAllFields(): void
    {
        $id    = (int)postv('tid');
        $page  = (int)postv('cfp');
        $count = (int)postv('ct');
        if ($id) {
            $rs = db()->select(
                'B.field, B.value, A.created',
                ['[+prefix+]cfformdb A', 'LEFT JOIN [+prefix+]cfformdb_detail B ON A.postid=B.postid'],
                'A.postid=' . $id,
                'B.`rank` ASC'
            );
            if (db()->count($rs)) {
                $records = [];
                $created = '';
                while ($buf = db()->getRow($rs)) {
                    $created      = $buf['created'];
                    unset($buf['created']);
                    $buf['value'] = nl2br($buf['value']);
                    $buf['field'] = $this->getLabel($buf['field']);
                    $records[]    = $buf;
                }
                $tbl = new MakeTable();
                $tbl->setTableClass('grid');
                $tbl->setRowRegularClass('gridItem');
                $tbl->setRowAlternateClass('gridAltItem');
                $this->data['content'] = sprintf(
                    '<div class="sectionBody">%s%s</div>',
                    sprintf("<p>ID: %d<br />投稿日時：%s</p>", $id, htmlspecialchars($created, ENT_QUOTES, 'UTF-8')),
                    $this->parser($tbl->create($records, ['field' => '項目', 'value' => '登録内容']), $this->data)
                );
                $this->data['add_buttons'] = $this->parser(
                    sprintf(
                        '<li><a href="[+posturl+]&amp;mode=list&amp;cfp=%d&amp;ct=%d"><img src="[+icons_cancel+]" />一覧に戻る</a></li><li><a href="#" onclick="submitAction(\'delete\', %d);return false;"><img src="[+icons_delete+]" />削除</a></li>',
                        $page, $count, $id
                    ),
                    $this->data
                );
            }
        }
    }

    private function delete(): void
    {
        $id = (int)postv('tid');
        if ($id) {
            db()->delete('[+prefix+]cfformdb', 'postid=' . $id);
            db()->delete('[+prefix+]cfformdb_detail', 'postid=' . $id);
            $this->data['content'] = $this->parser(
                '<div class="section"><div class="sectionBody"><p>ID: ' . $id . 'の投稿を削除しました<br /><ul class="actionButtons"><li><a href="[+posturl+]"><img src="[+icons_save+]" />戻る</a></li></ul></div></div>',
                $this->data
            );
        }
    }

    private function csv(): void
    {
        $rs = db()->select('COUNT(*)', '[+prefix+]cfformdb');
        if (!db()->count($rs)) {
            alert()->setError(1, '出力するデータがありません');
            alert()->dumpError();
            return;
        }

        $rs     = db()->select('DISTINCT(field)', '[+prefix+]cfformdb_detail', '', '`rank`');
        $loop   = 0;
        $fields = [];
        $tpl    = '<input type="checkbox" name="fields[]" value="%s" id="f_%d" %s /> <label for="f_%d">%s</label>';
        while ($buf = db()->getRow($rs)) {
            $checked  = in_array($buf['field'], $this->ignoreParams) ? '' : 'checked="checked"';
            $label    = $this->getLabel($buf['field']);
            $fields[] = sprintf($tpl, htmlspecialchars($buf['field'], ENT_QUOTES, 'UTF-8'), $loop, $checked, $loop, htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));
            $loop++;
        }
        $params                      = $this->data;
        $params['fields']            = implode("<br />", $fields);
        $params['site_url']          = evo()->config['site_url'];
        $params['manager_url']       = MODX_MANAGER_URL;
        $params['mgrlog_datefr']     = 'この日付から';
        $params['mgrlog_dateto']     = 'この日付まで';
        $params['datepicker_offset'] = evo()->config['datepicker_offset'];
        $params['datetime_format']   = evo()->config['datetime_format'];
        $params['dayNames']          = "['日','月','火','水','木','金','土']";
        $params['monthNames']        = "['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月']";
        $params['display']           = (event()->params['sel_csv_fields'] ?? '') === '1' ? '' : 'none';

        $this->data['content']      = $this->parser($this->loadTemplate('csv_settings.tpl'), $params);
        $this->data['add_buttons']  = $this->parser(
            '<li><a href="[+posturl+]&amp;mode=list"><img src="[+icons_refresh+]" /> 一覧表示</a></li><li><a href="index.php?a=2"><img src="[+icons_cancel+]" /> 閉じる</a></li>',
            $this->data
        );
    }

    private function generateCSV(): void
    {
        $postFields = $_POST['fields'] ?? [];
        if (!is_array($postFields) || count($postFields) === 0) {
            echo '<script>alert("出力する項目がありません");location.href="' . $this->data["posturl"] . '";</script>';
            exit;
        }

        $fields = [];
        $labels = [];
        foreach ($postFields as $val) {
            $val      = (string)$val;
            $fields[] = "'" . db()->escape($val) . "'";
            $labels[$val] = $this->getLabel($val);
        }

        $countRaw = $_POST['count'] ?? '0';
        $count    = in_array($countRaw, ['30', '50', '100'], true) ? (int)$countRaw : 0;
        $sortRaw  = $_POST['sort'] ?? '0';
        $sort     = ($sortRaw === '1') ? "created DESC" : "created ASC";

        // 日付文字列を正規化（日付のみの場合は時刻を補完、不正な形式は除外）
        $startRaw = !empty($_POST['start']) ? trim(db()->escape($_POST['start'])) : '';
        $endRaw   = !empty($_POST['end'])   ? trim(db()->escape($_POST['end']))   : '';

        // 日付のみ（YYYY-MM-DD）の場合は時刻を補完
        if (!empty($startRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw)) {
            $startRaw .= ' 00:00:00';
        }
        if (!empty($endRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endRaw)) {
            $endRaw .= ' 23:59:59';
        }

        // 日時形式チェック（YYYY-MM-DD HH:II:SS）
        if (!empty($startRaw) && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $startRaw)) {
            $startRaw = '';
        }
        if (!empty($endRaw) && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $endRaw)) {
            $endRaw = '';
        }

        // WHERE句はキーワードなしの条件式のみ（DBAPIが自動でWHEREを付加するため）
        if (!empty($startRaw) && !empty($endRaw)) {
            $where = "created BETWEEN '{$startRaw}' AND '{$endRaw}'";
        } elseif (!empty($startRaw)) {
            $where = "created >= '{$startRaw}'";
        } elseif (!empty($endRaw)) {
            $where = "created <= '{$endRaw}'";
        } else {
            $where = '';
        }

        header('Content-type: application/octet-stream');
        header('Content-Disposition: attachment; filename=cfoutput.csv');

        ob_start();
        $rs = db()->select('postid,created', '[+prefix+]cfformdb', $where, $sort, $count ? (string)$count : '');
        echo '//' . implode(',', array_merge(['ID'], array_values($labels), ['datetime'])) . "\n";
        while ($buf = db()->getRow($rs)) {
            echo (int)$buf['postid'] . ',';
            $detail_rs = db()->select(
                '*',
                '[+prefix+]cfformdb_detail',
                sprintf('postid=%d AND field IN (%s)', (int)$buf['postid'], implode(',', $fields)),
                '`rank` asc'
            );
            $detail = [];
            while ($detail_buf = db()->getRow($detail_rs)) {
                if (in_array($detail_buf['field'], $postFields)) {
                    $detail[$detail_buf['field']] = str_replace('"', '""', $detail_buf['value']);
                }
            }
            foreach ($postFields as $field) {
                echo '"' . ($detail[$field] ?? '') . '",';
            }
            echo '"' . $buf['created'] . '"' . "\n";
        }
        $output = ob_get_clean();
        $output = mb_convert_encoding($output, 'SJIS', 'UTF-8');
        header("Content-Length: " . strlen($output));
        echo $output;
        exit;
    }

    private function createTable(): void
    {
        if ($this->ifTableExists()) {
            $content = 'テーブルは存在しています';
        } else {
            $flag = false;
            db()->query('START TRANSACTION');
            db()->query(sprintf(
                "CREATE TABLE %s (`postid` int AUTO_INCREMENT PRIMARY KEY, `created` datetime) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
                evo()->getFullTableName('cfformdb')
            ));
            if (!db()->getLastError()) {
                db()->query(sprintf(
                    "CREATE TABLE %s (`postid` int NOT NULL, `field` varchar(191) NOT NULL, `value` text, `rank` int, PRIMARY KEY (`postid`, `field`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
                    evo()->getFullTableName('cfformdb_detail')
                ));
                if (!db()->getLastError()) {
                    db()->query('COMMIT');
                    $flag = true;
                }
            }

            if ($flag) {
                $content = $this->parser(
                    '<div class="section"><div class="sectionBody">テーブルを作成しました<br /><ul class="actionButtons"><li><a href="[+posturl+]"><img src="[+icons_save+]" />戻る</a></li></ul></div></div>',
                    $this->data
                );
            } else {
                db()->query('ROLLBACK');
                $content = 'テーブル作成に失敗しました';
            }
        }
        $this->data['content'] = $content;
    }

    private function ifTableExists(): bool
    {
        $rs = db()->query(sprintf(
            "SHOW TABLES FROM `%s` LIKE '%%cfformdb%%'",
            db()->config['dbase']
        ));
        if (!$rs || db()->count($rs) != 2) {
            return false;
        }
        return true;
    }

    private function getLabel(string $f = ''): string
    {
        if (empty($f)) {
            return '';
        }
        if (empty($this->headLabel[$f])) {
            return $f;
        }
        return $this->headLabel[$f];
    }

    private function view(): string
    {
        $tpl = $this->loadTemplate('main.tpl');
        if ($tpl !== false) {
            $tpl = $this->parser($tpl, $this->data);
            $tpl = preg_replace("/\[\+.+?\+]/", "", $tpl);
            echo $tpl;
        } else {
            alert()->setError(1, 'テンプレートの読み込みに失敗しました');
            alert()->dumpError();
        }
        return "";
    }

    private function loadTemplate(string $tplname): string|false
    {
        $filename = __DIR__ . '/' . $tplname;
        if (!file_exists($filename)) {
            return false;
        }
        return file_get_contents($filename);
    }

    private function parser(string|array $tpl, array $vars = []): string
    {
        global $_style;
        if (is_array($tpl)) {
            $tpl = implode("\n", $tpl);
        }
        if (!$vars) {
            return $tpl;
        }
        foreach ($vars as $key => $val) {
            $tpl = str_replace("[+" . $key . "+]", (string)$val, $tpl);
        }
        if (is_array($_style)) {
            foreach ($_style as $key => $val) {
                $tpl = str_replace("[+" . $key . "+]", (string)$val, $tpl);
            }
        }
        return $tpl;
    }
}

if (!function_exists('evo')) {
    function evo()
    {
        global $modx;
        if (!$modx) {
            return false;
        }
        return $modx;
    }
}

if (!function_exists('db')) {
    function db()
    {
        return evo()->db;
    }
}

if (!function_exists('event')) {
    function event()
    {
        return evo()->event;
    }
}

if (!function_exists('postv')) {
    function postv(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('alert')) {
    function alert(): errorHandler
    {
        static $e = null;
        if ($e !== null) {
            return $e;
        }
        include_once(MODX_CORE_PATH . 'error.class.inc.php');
        $e = new errorHandler();
        return $e;
    }
}
