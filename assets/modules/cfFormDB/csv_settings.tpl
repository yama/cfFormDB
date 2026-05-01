<link href="[+manager_url+]media/script/air-datepicker/css/datepicker.min.css" rel="stylesheet" type="text/css">
<script type="text/javascript" src="[+manager_url+]media/script/jquery/jquery.min.js"></script>
<script type="text/javascript" src="[+manager_url+]media/script/air-datepicker/datepicker.min.js"></script>
<script type="text/javascript" src="[+manager_url+]media/script/air-datepicker/i18n/datepicker.ja.js"></script>
<script type="text/javascript">
jQuery(function() {
    var options = {
        language: 'ja',
        timepicker: false,
        todayButton: new Date(),
        keyboardNav: false,
        autoClose: true,
        toggleSelected: false,
        clearButton: true,
        dateFormat: 'yyyy-mm-dd',
        navTitles: { days: 'yyyy/mm' }
    };
    jQuery('#start, #end').datepicker(options);
});
</script>
<div class="sectionHeader">CSV出力設定</div>
<div class="sectionBody">
<p>下記設定を確認し、「出力する」ボタンをクリックしてください</p>
<form action="[+posturl+]" method="post" name="csvform" id="mutate">
<input type="hidden" name="csrf_token" value="[+csrf_token+]" />
<input type="hidden" name="mode" value="csv_generate" />
<table class="grid" style="margin-bottom: 30px;">
  <thead>
    <tr>
      <th style="width: 20%">項目</th>
      <th style="width: 80%">設定</th>
    </tr>
  </thead>
  <tbody>
    <tr style="display:[+display+];">
      <th>出力する項目</th>
      <td>[+fields+]</td>
    </tr>
    <tr class="gridAltItem">
      <th>出力件数</th>
      <td><select name="count"><option value="0">すべて</option><option value="30">30件</option><option value="50">50件</option><option value="100">100件</option></select></td>
    </tr>
    <tr>
      <th>[+mgrlog_datefr+]</th>
      <td>
        <input type="text" id="start" name="start" class="DatePicker imeoff" value="" />
        <a onclick="jQuery('#start').data('datepicker') && jQuery('#start').data('datepicker').clear(); document.csvform.start.value=''; return false;" style="cursor:pointer;">
          <img src="[+manager_url+]media/style/common/images/icons/cal_nodate.gif" border="0" alt="No date" />
        </a>
      </td>
    </tr>
    <tr>
      <th>[+mgrlog_dateto+]</th>
      <td>
        <input type="text" id="end" name="end" class="DatePicker imeoff" value="" />
        <a onclick="jQuery('#end').data('datepicker') && jQuery('#end').data('datepicker').clear(); document.csvform.end.value=''; return false;" style="cursor:pointer;">
          <img src="[+manager_url+]media/style/common/images/icons/cal_nodate.gif" border="0" alt="No date" />
        </a>
      </td>
    </tr>
    <tr>
      <th>出力順</th>
      <td><select name="sort"><option value="0">日付の古いものから</option><option value="1">日付の新しいものから</option></select></td>
    </tr>
  </tbody>
</table>
<div class="actionButtons"><a href="#" onclick="document.csvform.submit();return false;"><img src="[+icons_save+]" />出力する</a></div>
</form>
</div>
