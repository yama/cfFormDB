<?php

/**
 * cfFromDB
 * 
 * cfFormMailerで投稿された情報を記録、表示、CSV出力
 * 
 * @author		Clefarray Factory
 * @version	1.0.1
 * @internal	@properties  &viewFields=一覧画面で表示する項目;text; &ignoreFields=無視する項目;text; &defaultView=デフォルト画面;list;list,csv;list &sel_csv_fields=CSV出力項目を選択;list;1,0;1 &headLabels=表示や出力時のヘッダラベル<br>【書式】name|ラベル,name2|ラベル2,…;textarea;
 *
 */
class cfFormDB
{
    private $data;
    private $version = '1.0.1';
    private $ignoreParams;
    private $headLabel;

    public function __construct()
    {
        global $manager_theme, $content;

        evo()->loadExtension('MakeTable');

        $this->data['theme']     = '/' . $manager_theme;
        $this->data['posturl']   = 'index.php?a=112&id=' . $content['id'];
        $this->data['pagetitle'] = $content['name'] . ' v' . $this->version;

        $this->ignoreParams = event()->params['ignoreFields'];
        if (!empty($this->ignoreParams)) {
            $this->ignoreParams = explode(',', $this->ignoreParams);
        } else {
            $this->ignoreParams = array();
        }

    /*
     *ラベルを任意に設定できるように調整。
     * フォームからPostされるnameとラベルの対応を以下のようにモジュール設定画面で定義すると反映する感じ。
     * name|ラベル,name2|ラベル2,name3|ラベル3, …
     * |か;でnameとラベルのペアを区切る。カンマでペアを増やす感じ。
     */
        $this->headLabel = array();
        if (empty(event()->params['headLabels'])) {
            return;
        }
        $headLabels = explode(',', event()->params['headLabels']);
        foreach ($headLabels as $item) {
            preg_match('/(.+)[|;](.+)/', $item, $m);
            $this->headLabel[$m[1]] = $m[2];
        }
    }

    /**
     * メインアクション
     */
    public function action()
    {

        if (!IN_MANAGER_MODE) {
            return false;
        }

        // 処理分岐
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

        // 出力
        $this->view();
        return true;
    }

    /**
     * 登録済みデータの一覧表示
     * 
     */
    private function defaultAction()
    {
        global $content;

        if (!$this->ifTableExists()) {
            // テーブルが存在しない場合
            $this->data['content'] = $this->parser(
                $this->loadTemplate('tablecreate.tpl'),
                $this->data
            );
            return false;
        }

        // cfformdb_detailテーブルのfieldカラムがvarchar(255)の場合はvarchar(191)に変更
        $rs = db()->query(sprintf(
            "SHOW COLUMNS FROM %s LIKE 'field'",
            evo()->getFullTableName('cfformdb_detail')
        ));
        $column = db()->getRow($rs);
        if ($column['Type'] === 'varchar(255)') {
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

        $defaultView = isset(event()->params['defaultView']) ? event()->params['defaultView'] : 'list';
        if ($defaultView === 'csv' && !isset($_GET['mode'])) {
            $this->csv();
            return true;
        }
        /**
         * 登録済みデータの一覧表示
         */
        $rs = db()->select('postid, created', '[+prefix+]cfformdb', '', 'created DESC');
        $records = array();
        if (db()->count($rs) <= 0) {
            $this->data['content'] = '<div class="sectionBody">データはありません</div>';
            return true;
        }
        // 表示する項目を取得
        $viewParams = event()->params['viewFields'];
        if ($viewParams) {
            $viewParamsArr = explode(",", str_replace(" ", "", $viewParams));
            foreach ($viewParamsArr as $val) {
                $viewParamsWhere[] = "'" . db()->escape($val) . "'";
            }
        } else {
            $viewParamsWhere = array();
        }

        // 総件数を取得
        $total = db()->count($rs);

        // ページ分割
        $count = isset($_GET['ct']) ? intval($_GET['ct']) : 30;
        $page = isset($_GET['cfp']) ? intval($_GET['cfp']) : 1;
        $start = ($page - 1) * $count;
        $maxPage = (int)($total / $count) + 1;
        $pageNav = array();
        if ($maxPage > 1) {
            if ($page > 1) {
                $pageNav[] = sprintf(
                    '<a href="%s&amp;cfp=%d&amp;ct=%d">&laquo;前</a>',
                    $this->data['posturl'],
                    ($page - 1),
                    $count
                );
            }
            for ($i = 1; $i <= $maxPage; $i++) {
                if ($i == $page) {
                    $pageNav[] = sprintf('<strong>%d</strong>', $i);
                } else {
                    $pageNav[] = sprintf(
                        '<a href="%s&amp;cfp=%d&amp;ct=%d">%d</a>',
                        $this->data['posturl'],
                        $i,
                        $count,
                        $i
                    );
                }
            }
            if ($page < $maxPage) {
                $pageNav[] = sprintf(
                    '<a href="%s&amp;cfp=%d&amp;ct=%d">次&raquo;</a>',
                    $this->data['posturl'],
                    ($page + 1),
                    $count
                );
            }
            $params['pageNav'] = implode('&nbsp;|&nbsp;', $pageNav);
        }

        // 投稿を取得
        $rs = db()->select('postid, created', '[+prefix+]cfformdb', '', 'created DESC', $start . ',' . $count);
        $field_keys = array();
        $loop = 0;
        while ($buf = db()->getRow($rs)) {
            $where = 'postid=' . $buf['postid'] . (count($viewParamsWhere) ? ' AND field IN (' . implode(",", $viewParamsWhere) . ')' : '');
            $detail_rs = db()->select('field,value', '[+prefix+]cfformdb_detail', $where, 'rank ASC');
            $records[$loop]['id'] = $buf['postid'];
            while ($detail_buf = db()->getRow($detail_rs)) {
                if (in_array($detail_buf['field'], $this->ignoreParams)) continue;
                if (100 < mb_strlen($detail_buf['value'], 'utf8'))
                    $detail_buf['value'] = mb_substr($detail_buf['value'], 0, 100, 'utf8') . ' ...';
                $records[$loop][$detail_buf['field']] = $detail_buf['value'];
                $field_keys[$detail_buf['field']] = $this->getLabel($detail_buf['field']);
            }
            $records[$loop]['created'] = $buf['created'];
            $records[$loop]['view'] = '<a href="[+posturl+]" onclick="submitAction(\'allfields\', ' . $buf['postid'] . ');return false;"><img src="[+icons_preview_resource+]" />詳細表示</a>';
            $records[$loop]['delete'] = '<a href="[+posturl+]" onclick="submitAction(\'delete\', ' . $buf['postid'] . ');return false;"><img src="[+icons_delete+]" />削除</a>';
            $loop++;
        }

        $tbl = new MakeTable();
        $tbl->setTableClass('grid');
        $tbl->setRowHeaderClass('gridHeader');
        $tbl->setRowRegularClass('gridItem');
        $tbl->setRowAlternateClass('gridAltItem');
        $listTableHeader = array_merge(
            array(
                'id' => 'ID',
                'created' => '投稿日時',
                'view' => '表示',
                'delete' => '削除'
            ),
            $field_keys
        );

        foreach (array(30, 50, 100) as $val) {
            $params['countlist'] .= sprintf('<option value="%d"%s>%d件</option>', $val, ($val == $count ? ' selected="selected"' : ''), $val) . "\n";
        }
        $params['moduleid'] = $content['id'];
        $params['list'] = $this->parser($tbl->create($records, $listTableHeader), $this->data);
        $params['total'] = $total;
        $this->data['content'] = $this->parser($this->loadTemplate('list.tpl'), $params);
        $this->data['page'] = $page;
        $this->data['count'] = $count;
        $this->data['add_buttons'] = $this->parser(
            array(
                '<li><a href="#" onclick="submitAction(\'csv\',\'\');return false;"><img src="[+icons_save+]" /> CSV出力</a></li>',
                '<li><a href="[+posturl+]"><img src="[+icons_refresh+]" /> 再読み込み</a></li>',
                '<li><a href="index.php?a=2"><img src="[+icons_cancel+]" /> 閉じる</a></li>'
            ),
            $this->data
        );
    }

    /**
     * 指定IDのすべての項目を表示
     * 
     */
    private function viewAllFields()
    {
        $id = (int)postv('tid');
        $page = (int)postv('cfp');
        $count = (int)postv('ct');
        if ($id) {
            $rs = db()->select(
                'B.field, B.value, A.created',
                array(
                    '[+prefix+]cfformdb A',
                    'LEFT JOIN [+prefix+]cfformdb_detail B ON A.postid=B.postid'
                ),
                'A.postid=' . $id,
                'B.rank ASC'
            );
            if (db()->count($rs)) {
                while ($buf = db()->getRow($rs)) {
                    $created = $buf['created'];
                    unset($buf['created']);
                    $buf['value'] = nl2br($buf['value']);
                    $buf['field'] = $this->getLabel($buf['field']);
                    $records[] = $buf;
                }

                $tbl = new MakeTable();
                $tbl->setTableClass('grid');
                $tbl->setRowRegularClass('gridItem');
                $tbl->setRowAlternateClass('gridAltItem');
                $this->data['content'] = sprintf(
                    '<div class="sectionBody">%s%s</div>',
                    sprintf(
                        "<p>ID: %d<br />投稿日時：%s</p>",
                        $id,
                        $created
                    ),
                    $this->parser(
                        $tbl->create($records, array('field' => '項目', 'value' => '登録内容')),
                        $this->data
                    )
                );
                $this->data['add_buttons']  = $this->parser(
                    sprintf(
                        '<li><a href="[+posturl+]&amp;mode=list&amp;cfp=%d&amp;ct=%d"><img src="[+icons_cancel+]" />一覧に戻る</a></li><li><a href="#" onclick="submitAction(\'delete\', %d);return false;"><img src="[+icons_delete+]" />削除</a></li>
            ',
                        $page,
                        $count,
                        $id
                    ),
                    $this->data
                );
            }
        }
    }

    /**
     * 投稿を削除
     * 
     */
    private function delete()
    {
        $id = (int)postv('tid');
        if ($id) {
            db()->delete('[+prefix+]cfformdb', 'postid=' . $id);
            db()->delete('[+prefix+]cfformdb_detail', 'postid=' . $id);
            $this->data['content'] = $this->parser('
        <div class="section">
        <div class="sectionBody">
        <p>ID: ' . $id . 'の投稿を削除しました<br />
        <ul class="actionButtons">
            <li><a href="[+posturl+]"><img src="[+icons_save+]" />戻る</a></li>
        </ul>
        </div>
        </div>', $this->data);
        }
    }

    /**
     * CSV出力のための設定画面
     * 
     */
    private function csv()
    {

        // 件数チェック
        $rs = db()->select('COUNT(*)', '[+prefix+]cfformdb');
        if (!db()->count($rs)) {
            alert()->setError(1, '出力するデータがありません');
            alert()->dumpError();
            return;
        }

        // 項目一覧を取得
        $rs = db()->select('DISTINCT(field)', '[+prefix+]cfformdb_detail', '', 'rank');
        $loop = 0;
        $fields = array();
        $tpl = '<input type="checkbox" name="fields[]" value="%s" id="f_%d" %s /> <label for="f_%d">%s</label>';
        while ($buf = db()->getRow($rs)) {
            $checked = in_array($buf['field'], $this->ignoreParams) ? '' : 'checked="checked"';
            $label = $this->getLabel($buf['field']);
            $fields[] = sprintf($tpl, $buf['field'], $loop, $checked, $loop, $label);
            $loop++;
        }
        $params = $this->data;
        $params['fields'] = implode("<br />", $fields);
        $params['site_url'] = evo()->config['site_url'];
        $params['manager_url'] = MODX_MANAGER_URL;
        $params['mgrlog_datefr'] = 'この日付から';
        $params['mgrlog_dateto'] = 'この日付まで';
        $params['datepicker_offset'] = evo()->config['datepicker_offset'];
        $params['datetime_format']   = evo()->config['datetime_format'];
        $params['dayNames']          = "['日','月','火','水','木','金','土']";
        $params['monthNames']        = "['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月']";
        $params['display'] = event()->params['sel_csv_fields'] === '1' ? '' : 'none';

        $this->data['content'] = $this->parser($this->loadTemplate('csv_settings.tpl'), $params);
        $this->data['add_buttons']  = $this->parser('
    <li><a href="[+posturl+]&amp;mode=list=list"><img src="[+icons_refresh+]" /> 一覧表示</a></li>
    <li><a href="index.php?a=2"><img src="[+icons_cancel+]" /> 閉じる</a></li>
    ', $this->data);
    }

    /**
     * CSV形式で出力
     * 
     */
    private function generateCSV()
    {

        // 出力する項目を取得
        if (!count($_POST['fields'])) {
            echo '<script>alert("出力する項目がありません");location.href="' . $this->data["posturl"] . '";</script>';
            exit;
        }

        $fields = array();
        $labels = array();
        foreach ($_POST['fields'] as $val) {
            $fields[] = "'" . $val . "'";
            $labels[$val] = $this->getLabel($val);
        }
        // 出力数
        switch ($_POST['count']) {
            case "30":
                $count = 30;
                break;
            case "50":
                $count = 50;
                break;
            case "100":
                $count = 100;
                break;
            default:
                $count = 0;  // すべて
        }
        // ソート
        $sort = ($_POST['sort'] ? "created DESC" : "created ASC");
        // 期間指定
        $start = !empty($_POST['start']) ? db()->escape($_POST['start']) : 0;
        $end   = !empty($_POST['end'])   ? db()->escape($_POST['end'])   : 0;
        if (!empty($start) && !empty($end)) {
            $where = "WHERE created BETWEEN '{$start}' AND '{$end}'";
        } elseif (!empty($start)) {
            $where = "WHERE created >= '{$start}'";
        } elseif (!empty($end)) {
            $where = "WHERE created <= '{$end}'";
        } else $where = '';
        // データ出力
        header('Content-type: application/octet-stream');
        header('Content-Disposition: attachment; filename=cfoutput.csv');

        ob_start();
        $loop = 0;
        $rs = db()->select(
            'postid,created',
            '[+prefix+]cfformdb',
            $where,
            $sort,
            $count ? $count : ''
        );
        echo '//' . implode(',', array_merge(array('ID'), array_values($labels), array('datetime'))) . "\n";
        while ($buf = db()->getRow($rs)) {
            echo $buf['postid'] . ',';
            $detail_rs = db()->select(
                '*',
                '[+prefix+]cfformdb_detail',
                sprintf('postid=%d AND field IN (%s)', $buf['postid'], implode(',', $fields)),
                'rank asc'
            );
            $detail = array();
            while ($detail_buf = db()->getRow($detail_rs)) {
                if (in_array($detail_buf['field'], $_POST['fields'])) {
                    if (strpos($detail_buf['value'], '"') !== false)
                        $detail_buf['value'] = str_replace('"', '""', $detail_buf['value']);
                    $detail[$detail_buf['field']] = $detail_buf['value'];
                }
            }
            foreach ($_POST['fields'] as $field) {
                echo '"' . $detail[$field] . '",';
            }
            echo '"' . $buf['created'] . '"' . "\n";
            $loop++;
        }
        $output = ob_get_flush();
        ob_end_clean();
        $output = mb_convert_encoding($output, 'sjis', 'utf-8');
        header("Content-Length: " . strlen($output));
        echo $output;
        exit;
    }

    /**
     * 新規テーブル作成
     */
    private function createTable()
    {
        if ($this->ifTableExists()) {
            $content = 'テーブルは存在しています';
        } else {
            $flag = false;
            db()->query('START TRANSACTION');
            db()->query(
                sprintf(
                    "CREATE TABLE %s (`postid` int auto_increment primary key, `created` datetime) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE utf8_general_ci",
                    evo()->getFullTableName('cfformdb')
                )
            );
            if (!db()->getLastError()) {
                db()->query(
                    sprintf(
                        "CREATE TABLE %s (`postid` int not null, `field` varchar(255) not null, `value` text, `rank` int, PRIMARY KEY ( `postid` , `field` )) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE utf8_general_ci",
                        evo()->getFullTableName('cfformdb_detail')
                    )
                );
                if (!db()->getLastError()) {
                    db()->query('COMMIT');
                    $flag = true;
                }
            }

            if ($flag) {
                $content = $this->parser('
        <div class="section">
        <div class="sectionBody">
        テーブルを作成しました<br />
        <ul class="actionButtons">
            <li><a href="[+posturl+]"><img src="[+icons_save+]" />戻る</a></li>
        </ul>
        </div></div>', $this->data);
            } else {
                db()->query('ROLLBACK');
                $content = 'テーブル作成に失敗しました';
            }
        }
        $this->data['content'] = $content;
    }

    /**
     * テーブルの存在確認
     */
    private function ifTableExists()
    {
        $rs = db()->query(
            sprintf(
                "SHOW TABLES FROM `%s` LIKE '%%cfformdb%%'",
                db()->config['dbase']
            )
        );
        if (!$rs || db()->count($rs) != 2) {
            return false;
        }
        return true;
    }

    /**
     *　ラベル指定取得
     *    指定がなければそのまま
     */
    private function getLabel($f = '')
    {
        if (!empty($f)) {
            return '';
        }
        if (empty($this->headLabel[$f])) {
            return $f;
        }
        return $this->headLabel[$f];
    }

    /**
     * 画面出力
     *
     */
    private function view()
    {
        if ($tpl = $this->loadTemplate('main.tpl')) {
            $tpl = $this->parser($tpl, $this->data);
            $tpl = preg_replace("/\[\+.+?\+]/", "", $tpl);
            echo $tpl;
        } else {
            alert()->setError(1, 'テンプレートの読み込みに失敗しました');
            alert()->dumpError();
        }
        return "";
    }

    /**
     * テンプレート取得
     */
    private function loadTemplate($tplname)
    {
        $filename = __DIR__ . '/' . $tplname;
        if (!file_exists($filename)) {
            return false;
        }
        return file_get_contents($filename);
    }

    /**
     * テンプレート変数を展開
     */
    private function parser($tpl, $vars = array())
    {
        global $_style;
        if (is_array($tpl)) {
            $tpl = implode("\n", $tpl);
        }
        if (!$vars) {
            return $tpl;
        }

        foreach ($vars as $key => $val) {
            $tpl = str_replace("[+" . $key . "+]", $val, $tpl);
        }
        foreach ($_style as $key => $val) {
            $tpl = str_replace("[+" . $key . "+]", $val, $tpl);
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
    function postv($key, $default=null)
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return $_POST[$key];
    }
}

if (!function_exists('alert')) {
    function alert() {
        static $e = null;
        if ($e) {
            return $e;
        }
        include_once(MODX_CORE_PATH . 'error.class.inc.php');
        $e = new errorHandler;
        return $e;
    }
}