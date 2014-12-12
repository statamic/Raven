<div id="subnav">
  <ul>
  <li><a href="<?php echo $app->urlFor("raven") ?>"><?php echo Localization::fetch('Overview')?></a></li>
  <li class="separator">&nbsp;</li>
    <?php foreach ($formsets as $name => $values): ?>
      <li><a href="<?php echo $app->urlFor("raven") . '/' . $name ?>" <?php if ($formset['name'] === $name): ?> class="active"<?php endif ?> ><?php echo Slug::prettify($name) ?></a></li>
    <?php endforeach ?>
  </ul>
</div>

<div class="container">

  <div id="status-bar" class="web">
    <div class="status-block">
      <strong><?php echo Slug::prettify($formset['name']) ?></strong> <span class="muted"><?php echo Localization::fetch('form', null, true) ?> <?php echo Localization::fetch('submissions', null, true)?>
    </div>
    <ul>
      <?php if (isset($spam)): ?>
      <li>
        <a href="<?php echo $app->urlFor("raven") . '/' . $formset['name'] ?>/spam">
          <?php echo Localization::fetch('spam')?> (<?php echo count($spam)?>)
        </a>
      </li>
      <?php endif ?>
      <li>
        <a href="<?php echo $app->urlFor("raven") . '/' . $formset['name'] ?>/export">
          <span class="ss-icon">downloadfile</span>
          <?php echo Localization::fetch('export_csv')?>
        </a>
      </li>
    </ul>
  </div>
  
  <?php if ($metrics): ?>
  <div class="section">
    <table class="simple-table metrics">
      <tbody>
        <tr>
          <?php foreach ($metrics as $metric): ?>
            <td>
              <div class="label"><?php echo $metric['label'] ?></div>
              <?php if ( ! is_array($metric['metrics'])): ?>
                <div class="number"><?php echo $metric['metrics'] ?></div>
              <?php else: ?>
                <ul class="metric-list">
                <?php foreach ($metric['metrics'] as $key => $value): ?>
                  <li>
                    <div class="list-label"><?php echo $key ?></div>
                    <div class="list-value"><?php echo $value ?></div>
                  </li>
                <?php endforeach ?>
                </ul>
              <?php endif ?>
            </td>
          <?php endforeach ?>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif ?>
  
  <form action="<?php echo $app->urlFor('raven') . '/' . $formset['name'] . '/batch' ?>" method="POST">
    <div class="section">
      <table class="simple-table sortable">
        <thead>
          <tr>
            <th class="checkbox-col"></th>
            <?php foreach ($fields as $field => $name): ?>
              <th><?php echo $name ?></th>
            <?php endforeach ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($files as $file): ?>
            <tr>
              <td class="checkbox-col">
                <input type="checkbox" name="files[]" value="<?php echo array_get($file, 'meta:path') ?>" data-bind="checked: selectedFiles" >
              </td>
              <?php foreach ($fields as $field => $name): ?>
                <td>
                  <?php $val = array_get($file, 'fields:'.$field); ?>
                  <?php if ($field == $edit): ?>
                    <a href="/<?php echo Config::get('admin_path') . '.php/publish?path=' . array_get($file, 'meta:edit_path') . '&return=' . URL::getCurrent() ?>">
                  <?php endif ?>
                    <?php if (is_array($val)): ?>
                      <?php echo implode($val, ', '); ?>
                    <?php else: ?>
                      <?php echo $val ?>
                    <?php endif ?>
                  <?php if ($field == $edit): ?>
                    </a>
                  <?php endif ?>
                </td>
              <?php endforeach ?>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <div class="take-action clearfix">
      <div class="input-status block-action pull-left" data-bind="css: {disabled: selectedFiles().length < 1}">
        <div class="input-select-wrap">
          <select data-bind="enable: selectedFiles().length > 0, selectedOptions: selectedAction" name="action">
            <option value=""><?php echo Localization::fetch('take_action')?></option>
            <option value="delete"><?php echo Localization::fetch('delete_files')?></option>
            <option value="spam"><?php echo Localization::fetch('mark_as_spam')?></option>
          </select>
        </div>
      </div>
      <input type="submit" class="btn pull-left" data-bind="visible: selectedAction() != '' && selectedFiles().length > 0" value="<?php echo Localization::fetch('confirm_action')?>">
    </div>
  </div>
</form>

<script type="text/javascript">
  var viewModel = {
      selectedFiles: ko.observableArray(),
      selectedAction: ko.observable(''),
  };

  viewModel.selectedFiles.subscribe(function(item){
    // console.log('selected ' + item);
  }, viewModel);

  viewModel.selectedAction.subscribe(function(action) {
    // console.log('selected ' + action);
  }, viewModel);

  ko.applyBindings(viewModel);

</script>
